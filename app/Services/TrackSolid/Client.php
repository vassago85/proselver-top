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
 * TrackSolid Pro REST client — matches the v2.7.14 Open API specification.
 *
 * The TrackSolid platform exposes ONE endpoint, `/route/rest`, and the
 * different operations are dispatched on the `method` POST parameter
 * (e.g. `jimi.oauth.token.get`, `jimi.user.device.list`,
 * `jimi.device.location.get`).
 *
 * Every call carries seven common parameters
 * (method, app_key, sign, sign_method, timestamp, v, format) plus that
 * call's "private" parameters (e.g. `imeis`, `target`). Requests are
 * signed by sorting the params alphabetically by key, concatenating
 * `key+value` (no separator), wrapping the resulting string with
 * `app_secret` at both ends, MD5-hashing it, and uppercasing the hex.
 *
 * Configuration is loaded at runtime from `system_settings` (set on the
 * Integrations page in the developer dashboard) so secrets stay out of
 * git and ops can rotate them without redeploying.
 */
class Client implements TrackSolidClientInterface
{
    /**
     * Settings keys read from `system_settings`. Constants so the
     * settings UI and the poller can't drift on spelling.
     */
    public const SETTING_ENABLED = 'tracksolid_enabled';
    public const SETTING_BASE_URL = 'tracksolid_base_url';
    public const SETTING_APP_KEY = 'tracksolid_app_key';
    public const SETTING_APP_SECRET = 'tracksolid_app_secret';
    public const SETTING_ACCOUNT = 'tracksolid_account';
    public const SETTING_USER_PWD_MD5 = 'tracksolid_user_pwd_md5';
    public const SETTING_POLL_INTERVAL = 'tracksolid_poll_interval_seconds';

    /** Spec maximum: 100 IMEIs per location call. */
    private const LOCATION_BATCH_SIZE = 100;

    private const TOKEN_CACHE_KEY = 'tracksolid.access_token';

    /** Default expiry seconds requested at auth (max per spec is 7200). */
    private const TOKEN_TTL = 7200;

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

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_array($cached) && !empty($cached['token']) && ($cached['expires_at'] ?? 0) > time() + 30) {
            return $cached['token'];
        }

        $payload = $this->signedRequest('jimi.oauth.token.get', [
            'user_id' => $creds['account'],
            'user_pwd_md5' => $creds['user_pwd_md5'],
            'expires_in' => (string) self::TOKEN_TTL,
        ]);

        if (($payload['code'] ?? -1) !== 0) {
            throw new RuntimeException(sprintf(
                'TrackSolid auth rejected (code %s): %s',
                $payload['code'] ?? '?',
                $payload['message'] ?? 'no message'
            ));
        }

        $token = $payload['result']['accessToken'] ?? null;
        $ttl = max(60, (int) ($payload['result']['expiresIn'] ?? self::TOKEN_TTL));

        if (!$token) {
            throw new RuntimeException('TrackSolid auth response missing accessToken: ' . json_encode($payload));
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

        try {
            $payload = $this->signedRequest('jimi.user.device.list', [
                'access_token' => $token,
                'target' => $creds['account'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('TrackSolid listDevices failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (($payload['code'] ?? -1) !== 0) {
            Log::warning('TrackSolid listDevices error', $payload);
            return [];
        }

        $rows = $payload['result'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)->map(fn ($row) => [
            'imei' => (string) ($row['imei'] ?? ''),
            'name' => $row['deviceName'] ?? null,
            'status' => $row['enabledFlag'] ?? null,
            'vehicle_number' => $row['vehicleNumber'] ?? null,
            'driver_name' => $row['driverName'] ?? null,
            'vin' => $row['carFrame'] ?? null,
        ])->filter(fn ($d) => $d['imei'] !== '')->values()->all();
    }

    public function getLatestPositions(array $imeis): array
    {
        if (!$this->isConfigured() || empty($imeis)) {
            return [];
        }

        $token = $this->authenticate();
        $positions = [];

        foreach (array_chunk(array_values(array_unique($imeis)), self::LOCATION_BATCH_SIZE) as $batch) {
            try {
                $payload = $this->signedRequest('jimi.device.location.get', [
                    'access_token' => $token,
                    'imeis' => implode(',', $batch),
                ]);
            } catch (\Throwable $e) {
                Log::warning('TrackSolid getLatestPositions batch failed', [
                    'error' => $e->getMessage(),
                    'imei_count' => count($batch),
                ]);
                continue;
            }

            if (($payload['code'] ?? -1) !== 0) {
                Log::warning('TrackSolid getLatestPositions error', $payload);
                continue;
            }

            $rows = $payload['result'] ?? [];
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $normalised = $this->normalisePosition(is_array($row) ? $row : []);
                if ($normalised) {
                    $positions[] = $normalised;
                }
            }
        }

        return $positions;
    }

    public function getDevicePosition(string $imei): ?array
    {
        if ($imei === '') {
            return null;
        }
        $rows = $this->getLatestPositions([$imei]);
        return $rows[0] ?? null;
    }

    /**
     * Coerce an upstream location row into the canonical position shape.
     * Returns null if the row is missing the bits the wallboard relies
     * on (imei, lat, lng) or the device has expired (lat/lng both 0).
     */
    public function normalisePosition(array $row): ?array
    {
        $imei = (string) ($row['imei'] ?? '');
        $lat = $row['lat'] ?? null;
        $lng = $row['lng'] ?? null;

        if ($imei === '' || $lat === null || $lng === null) {
            return null;
        }

        $latF = (float) $lat;
        $lngF = (float) $lng;

        // The spec says "if the device expires, the value is 0" for both
        // lat and lng — drop those rows so we don't paint pins at (0,0)
        // off the coast of West Africa.
        if ($latF === 0.0 && $lngF === 0.0) {
            return null;
        }

        $rawTs = $row['gpsTime'] ?? $row['hbTime'] ?? null;
        $reportedAt = $this->parseTimestamp($rawTs)
            ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $heading = $row['direction'] ?? null;
        // -1 means "unknown" per the spec — surface as null.
        if ($heading === '' || $heading === null || (string) $heading === '-1') {
            $heading = null;
        }

        return [
            'tracker_id'  => $imei,
            'latitude'    => $latF,
            'longitude'   => $lngF,
            'speed_kmh'   => isset($row['speed']) && $row['speed'] !== '' ? (float) $row['speed'] : null,
            'heading_deg' => $heading !== null ? (float) $heading : null,
            'reported_at' => $reportedAt,
            'raw'         => $row,
        ];
    }

    /**
     * Build, sign, POST and decode a TrackSolid request. `$private` is
     * the call-specific params (already minus the seven common ones we
     * inject here); the signature covers the union of common+private.
     */
    private function signedRequest(string $method, array $private): array
    {
        $creds = $this->credentials();
        if (!$creds) {
            throw new RuntimeException('TrackSolid credentials are not configured.');
        }

        $params = array_merge([
            'method' => $method,
            'app_key' => $creds['app_key'],
            'sign_method' => 'md5',
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'v' => '1.0',
            'format' => 'json',
        ], $private);

        $params['sign'] = self::buildSignature($params, $creds['app_secret']);

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->post(rtrim($creds['base_url'], '/') . '/route/rest', $params);

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'TrackSolid HTTP %d on %s: %s',
                $response->status(),
                $method,
                mb_substr($response->body(), 0, 300)
            ));
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('TrackSolid returned non-JSON response on ' . $method);
        }
        return $json;
    }

    /**
     * Build the v=1.0 MD5 signature per the spec:
     *   1. Drop the `sign` field if present (it's what we're computing)
     *   2. Drop entries where the key OR value is empty
     *   3. Sort params alphabetically by key
     *   4. Concatenate key+value (no separators) into one string
     *   5. Wrap with app_secret on both ends
     *   6. md5() then UPPERCASE the hex digest
     *
     * Public+static so it's straightforward to unit-test against the
     * worked example in §7 of the spec.
     */
    public static function buildSignature(array $params, string $appSecret): string
    {
        unset($params['sign']);

        $params = array_filter($params, fn ($v, $k) => $k !== '' && $v !== '' && $v !== null, ARRAY_FILTER_USE_BOTH);
        ksort($params);

        $body = '';
        foreach ($params as $k => $v) {
            $body .= $k . $v;
        }

        return strtoupper(md5($appSecret . $body . $appSecret));
    }

    /**
     * Read the six required setting values. Returns null when any are
     * blank — the client treats that as "integration disabled" rather
     * than throwing partway through a poll.
     */
    private function credentials(): ?array
    {
        $base = trim((string) SystemSetting::get(self::SETTING_BASE_URL, ''));
        $appKey = trim((string) SystemSetting::get(self::SETTING_APP_KEY, ''));
        $appSecret = trim((string) SystemSetting::get(self::SETTING_APP_SECRET, ''));
        $account = trim((string) SystemSetting::get(self::SETTING_ACCOUNT, ''));
        $userPwdMd5 = trim((string) SystemSetting::get(self::SETTING_USER_PWD_MD5, ''));

        if ($base === '' || $appKey === '' || $appSecret === '' || $account === '' || $userPwdMd5 === '') {
            return null;
        }

        // Operators sometimes paste the FULL endpoint URL ending in
        // `/route/rest`; the builder appends that itself, so strip it
        // first to avoid a `/route/rest/route/rest` request.
        $base = preg_replace('#/route/rest/?$#', '', $base) ?: $base;

        return [
            'base_url' => rtrim($base, '/'),
            'app_key' => $appKey,
            'app_secret' => $appSecret,
            'account' => $account,
            'user_pwd_md5' => $userPwdMd5,
        ];
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
            // Some tracker payloads use ms-since-epoch, some s-since-epoch.
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
}
