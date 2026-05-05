<?php

namespace App\Services\TrackSolid;

use App\Models\SystemSetting;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TrackSolid Pro REST client.
 *
 * Configuration is read from the `system_settings` table (see the
 * Integrations settings page) at runtime — NOT from .env / config files.
 * That keeps secrets out of the repo and lets ops rotate the app key
 * without redeploying.
 *
 * The exact endpoint paths, auth header names and JSON shapes will be
 * filled in once the TrackSolid sandbox creds are available. The points
 * marked `// TODO[tracksolid]` are the spots that need verifying against
 * the v2.7.14 PDF; the surrounding plumbing (caching, normalisation,
 * graceful no-op when disabled) is final.
 */
class Client implements TrackSolidClientInterface
{
    /**
     * Settings keys we read from `system_settings`. Kept as constants so
     * the settings UI and the poller don't drift apart on the spelling.
     */
    public const SETTING_ENABLED = 'tracksolid_enabled';
    public const SETTING_BASE_URL = 'tracksolid_base_url';
    public const SETTING_APP_KEY = 'tracksolid_app_key';
    public const SETTING_APP_SECRET = 'tracksolid_app_secret';
    public const SETTING_ACCOUNT = 'tracksolid_account';
    public const SETTING_POLL_INTERVAL = 'tracksolid_poll_interval_seconds';

    private const TOKEN_CACHE_KEY = 'tracksolid.access_token';

    public function isConfigured(): bool
    {
        if (!filter_var(SystemSetting::get(self::SETTING_ENABLED, false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        return $this->credentials() !== null;
    }

    public function authenticate(): string
    {
        $creds = $this->credentials();
        if (!$creds) {
            throw new RuntimeException('TrackSolid credentials are not configured.');
        }

        // Cached token — bail out fast on the happy path so the poller
        // doesn't authenticate every 30 seconds.
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_array($cached) && !empty($cached['token']) && ($cached['expires_at'] ?? 0) > time() + 30) {
            return $cached['token'];
        }

        // TODO[tracksolid]: replace path + body shape per the v2.7.14 PDF.
        // The shape below assumes the typical "POST /auth/oauth/token with
        // appKey/appSecret/account and a JSON body returning access_token
        // + expiresIn" used by the JimiIoT/TrackSolid OEM platform. If the
        // PDF says different, only this method should change — callers
        // are unaffected.
        $response = Http::baseUrl($creds['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post('/route/rest', [
                'method' => 'jimi.oauth.token.get',
                'app_key' => $creds['app_key'],
                'sign' => $this->signature($creds),
                'account' => $creds['account'],
                'timestamp' => now()->format('Y-m-d H:i:s'),
                'v' => '0.9',
                'format' => 'json',
                'sign_method' => 'md5',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('TrackSolid auth HTTP ' . $response->status() . ': ' . $response->body());
        }

        $payload = $response->json();
        $token = $payload['result']['accessToken'] ?? $payload['access_token'] ?? null;
        $ttl = (int) ($payload['result']['expiresIn'] ?? $payload['expires_in'] ?? 3600);

        if (!$token) {
            throw new RuntimeException('TrackSolid auth response missing access token: ' . $response->body());
        }

        Cache::put(self::TOKEN_CACHE_KEY, [
            'token' => $token,
            'expires_at' => time() + $ttl,
        ], $ttl);

        return $token;
    }

    public function listDevices(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $creds = $this->credentials();
        $token = $this->authenticate();

        // TODO[tracksolid]: confirm endpoint name + paging behaviour. The
        // shape below assumes a single-page "list all devices on this
        // account" response. Real accounts may be paged; the loop should
        // be added once the upstream behaviour is confirmed.
        $response = Http::baseUrl($creds['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->withHeaders(['Access-Token' => $token])
            ->post('/route/rest', [
                'method' => 'jimi.user.device.list',
                'access_token' => $token,
                'target' => $creds['account'],
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ]);

        if (!$response->successful()) {
            $this->logUpstreamFailure('listDevices', $response);
            return [];
        }

        $rows = data_get($response->json(), 'result.list', []);
        return collect($rows)->map(fn ($row) => [
            'imei' => (string) ($row['imei'] ?? $row['deviceImei'] ?? ''),
            'name' => $row['deviceName'] ?? $row['name'] ?? null,
            'status' => $row['status'] ?? null,
        ])->filter(fn ($d) => $d['imei'] !== '')->values()->all();
    }

    public function getLatestPositions(array $imeis): array
    {
        if (!$this->isConfigured() || empty($imeis)) {
            return [];
        }

        $creds = $this->credentials();
        $token = $this->authenticate();

        // TODO[tracksolid]: confirm batch endpoint. If the API only
        // supports single-IMEI lookups, fall through to the per-imei
        // loop below.
        $response = Http::baseUrl($creds['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->post('/route/rest', [
                'method' => 'jimi.tracker.location.batch',
                'access_token' => $token,
                'imeis' => array_values($imeis),
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ]);

        if (!$response->successful()) {
            // Fall through to single-IMEI lookups so a partial outage
            // doesn't blank the entire wallboard.
            $this->logUpstreamFailure('getLatestPositions', $response);
            $positions = [];
            foreach ($imeis as $imei) {
                $row = $this->getDevicePosition($imei);
                if ($row) {
                    $positions[] = $row;
                }
            }
            return $positions;
        }

        $rows = data_get($response->json(), 'result.list', data_get($response->json(), 'result', []));
        return collect($rows)
            ->map(fn ($row) => $this->normalisePosition($row))
            ->filter()
            ->values()
            ->all();
    }

    public function getDevicePosition(string $imei): ?array
    {
        if (!$this->isConfigured() || $imei === '') {
            return null;
        }

        $creds = $this->credentials();
        $token = $this->authenticate();

        $response = Http::baseUrl($creds['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post('/route/rest', [
                'method' => 'jimi.tracker.location.get',
                'access_token' => $token,
                'imei' => $imei,
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ]);

        if (!$response->successful()) {
            $this->logUpstreamFailure('getDevicePosition', $response, ['imei' => $imei]);
            return null;
        }

        $row = data_get($response->json(), 'result', []);
        if (empty($row['imei']) && empty($row['deviceImei'])) {
            $row['imei'] = $imei; // single-device endpoints sometimes omit it
        }
        return $this->normalisePosition($row);
    }

    /**
     * Coerce an upstream payload into the canonical position shape.
     * Returns null when the row is missing the bits we need (lat/lng).
     */
    public function normalisePosition(array $row): ?array
    {
        $imei = (string) ($row['imei'] ?? $row['deviceImei'] ?? $row['tracker_id'] ?? '');
        $lat = $row['lat'] ?? $row['latitude'] ?? $row['gpsLat'] ?? null;
        $lng = $row['lng'] ?? $row['longitude'] ?? $row['gpsLng'] ?? null;

        if ($imei === '' || $lat === null || $lng === null) {
            return null;
        }

        $rawTs = $row['gpsTime'] ?? $row['reportedAt'] ?? $row['posTime'] ?? $row['time'] ?? null;
        $reportedAt = $this->parseTimestamp($rawTs) ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return [
            'tracker_id'  => $imei,
            'latitude'    => (float) $lat,
            'longitude'   => (float) $lng,
            'speed_kmh'   => isset($row['speed']) ? (float) $row['speed'] : null,
            'heading_deg' => isset($row['course'])   ? (float) $row['course']
                            : (isset($row['heading']) ? (float) $row['heading'] : null),
            'reported_at' => $reportedAt,
            'raw'         => $row,
        ];
    }

    /**
     * Read credentials from `system_settings`. Returns null when any of
     * the required fields are blank — the client treats that as
     * "integration disabled" rather than throwing.
     */
    private function credentials(): ?array
    {
        $base = (string) SystemSetting::get(self::SETTING_BASE_URL, '');
        $key = (string) SystemSetting::get(self::SETTING_APP_KEY, '');
        $secret = (string) SystemSetting::get(self::SETTING_APP_SECRET, '');
        $account = (string) SystemSetting::get(self::SETTING_ACCOUNT, '');

        if ($base === '' || $key === '' || $secret === '' || $account === '') {
            return null;
        }

        return [
            'base_url' => rtrim($base, '/'),
            'app_key' => $key,
            'app_secret' => $secret,
            'account' => $account,
        ];
    }

    /**
     * MD5 signature in the JimiIoT style: sort all query params alpha,
     * concatenate `key=value` pairs, prepend AND append the secret, then
     * md5() the result. Matches the canonical TrackSolid v2 sign rule.
     *
     * TODO[tracksolid]: confirm exactly which params are part of the sign
     * payload and whether v2.7.14 uses md5 or hmac-sha1; revise this
     * helper rather than mutating individual call sites.
     */
    private function signature(array $creds, array $extraParams = []): string
    {
        $params = array_merge([
            'method' => 'jimi.oauth.token.get',
            'app_key' => $creds['app_key'],
            'account' => $creds['account'],
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'v' => '0.9',
            'format' => 'json',
            'sign_method' => 'md5',
        ], $extraParams);

        ksort($params);
        $body = '';
        foreach ($params as $k => $v) {
            $body .= $k . $v;
        }
        return strtoupper(md5($creds['app_secret'] . $body . $creds['app_secret']));
    }

    private function parseTimestamp(mixed $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof DateTimeImmutable) {
            return $raw;
        }
        if (is_numeric($raw)) {
            // Some endpoints return ms-since-epoch, others s-since-epoch.
            $ts = (int) $raw;
            if ($ts > 9_999_999_999) {
                $ts = (int) ($ts / 1000);
            }
            return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
        }
        try {
            return new DateTimeImmutable((string) $raw, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function logUpstreamFailure(string $operation, $response, array $context = []): void
    {
        Log::warning('TrackSolid ' . $operation . ' failed', array_merge([
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ], $context));
    }
}
