<?php

namespace App\Services\Tfn;

use App\Services\Tfn\Exceptions\TfnException;
use App\Services\Tfn\Exceptions\TfnNotConfiguredException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Redis-backed cache for the TFN OAuth password-grant access token.
 *
 * TFN issues short-lived bearer tokens (typically 1h) via the password
 * grant. Every workflow call has to carry one, so a naive implementation
 * would re-authenticate on every request -- which is (a) slow and (b) a
 * good way to trip TFN's login-attempt lockout on a bad password.
 *
 * We keep the current token in the cache store (Redis in production, see
 * CACHE_STORE) with a TTL a bit shorter than TFN's reported `expires_in`
 * so the token never actually expires mid-request. `token()` is the only
 * public entrypoint the client needs; `invalidate()` is called by the
 * client when it sees a 401 (in case TFN has revoked the token
 * server-side and our cached copy is stale).
 *
 * A note on race conditions: the OAuth spec doesn't guarantee that two
 * concurrent logins get the same token, so we use Cache::lock() to
 * single-flight the refresh. Worst case under contention: one login
 * request, other workers wait on the lock and read the fresh token.
 */
class TfnTokenManager
{
    private const CACHE_KEY = 'token';
    private const LOCK_KEY = 'token:lock';
    private const LOCK_TIMEOUT_SECONDS = 15;

    /**
     * Return a bearer token suitable for the Authorization header,
     * refreshing transparently when the cached one is missing or near
     * expiry. Always returns a token or throws -- never null.
     */
    public function token(): string
    {
        $cached = Cache::get($this->key(self::CACHE_KEY));
        if ($cached) {
            return $cached;
        }

        // Single-flight: only one worker actually POSTs to /api/token,
        // the rest wait up to LOCK_TIMEOUT_SECONDS and re-read the cache.
        $lock = Cache::lock($this->key(self::LOCK_KEY), self::LOCK_TIMEOUT_SECONDS);
        try {
            $lock->block(self::LOCK_TIMEOUT_SECONDS);

            // Between the initial cache miss and grabbing the lock, another
            // worker may have populated the token. Re-check before firing
            // a request.
            $cached = Cache::get($this->key(self::CACHE_KEY));
            if ($cached) {
                return $cached;
            }

            return $this->refresh();
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Drop the cached token so the next `token()` call re-authenticates.
     * Called by the client on 401 in case the server-side session was
     * invalidated behind our back (e.g. TFN ops rotated the password).
     */
    public function invalidate(): void
    {
        Cache::forget($this->key(self::CACHE_KEY));
    }

    /**
     * Force a login round-trip regardless of cache state. Not usually
     * needed externally -- kept public for the debug/ping button on the
     * ops screen so the user can "reconnect" if something looks wrong.
     */
    public function refresh(): string
    {
        $this->assertConfigured();

        $response = Http::asForm()
            ->timeout(config('tfn.timeout'))
            ->acceptJson()
            ->post(rtrim(config('tfn.base_url'), '/') . '/api/token', [
                'grant_type' => 'password',
                'client_ID'  => config('tfn.client_id'),
                'username'   => config('tfn.username'),
                'password'   => config('tfn.password'),
            ]);

        if ($response->failed()) {
            // Don't log the password -- Laravel's HTTP client sanitises
            // request context automatically, but be explicit here.
            Log::warning('TFN token request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new TfnException(
                message: 'TFN authentication failed (HTTP ' . $response->status() . '). Check TFN_USERNAME / TFN_PASSWORD.',
                httpStatus: $response->status(),
                payload: $response->json() ?? [],
            );
        }

        $body = $response->json();
        $token = $body['access_token'] ?? null;
        $ttl = (int) ($body['expires_in'] ?? 0);

        if (!$token || $ttl <= 0) {
            throw new TfnException(
                message: 'TFN authentication succeeded but the response was missing access_token / expires_in.',
                payload: $body,
            );
        }

        $safeTtl = max(60, $ttl - (int) config('tfn.token_refresh_buffer'));
        Cache::put($this->key(self::CACHE_KEY), $token, now()->addSeconds($safeTtl));

        return $token;
    }

    private function assertConfigured(): void
    {
        if (!config('tfn.enabled')) {
            throw new TfnNotConfiguredException('TFN integration is disabled. Set TFN_ENABLED=true.');
        }

        foreach (['username', 'password', 'customer_number'] as $key) {
            if (blank(config("tfn.{$key}"))) {
                throw new TfnNotConfiguredException(
                    "TFN configuration is incomplete: missing tfn.{$key} (set TFN_" . strtoupper($key) . ' in .env).'
                );
            }
        }
    }

    private function key(string $suffix): string
    {
        return config('tfn.cache_prefix', 'tfn:') . $suffix;
    }
}
