<?php

namespace App\Listeners;

use App\Models\LoginHistory;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists every authentication event into `login_history`.
 *
 * Registered in AppServiceProvider::boot() via three Event::listen() calls
 * (one per event class).  We do NOT put dispatchesEvents-style discovery
 * annotations here because this project doesn't use Laravel's event
 * auto-discovery — everything is wired explicitly for grepability.
 *
 * Failure isolation
 * -----------------
 * Every handler is wrapped in try/catch and reports to the log stack.
 * A broken audit sink must never be able to abort a login: users must
 * always be able to sign in, and admins must always be able to sign out,
 * even if the login_history table is missing, misconfigured or full.
 */
class LogLoginActivity
{
    public function handleLogin(Login $event): void
    {
        try {
            LoginHistory::create([
                'user_id'    => $event->user?->getAuthIdentifier(),
                'identity'   => $this->identityFromRequest(),
                'event'      => 'login',
                'ip_address' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'session_id' => $this->sessionId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('login_history: failed to record login event', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        try {
            LoginHistory::create([
                // Fortify's Failed event may carry a resolved user (rare —
                // e.g. wrong password on a known account); if not, we still
                // record the row against the identity string they typed.
                'user_id'    => $event->user?->getAuthIdentifier(),
                'identity'   => $this->identityFromRequest()
                    ?? $this->identityFromCredentials($event->credentials),
                'event'      => 'failed',
                'ip_address' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'session_id' => $this->sessionId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('login_history: failed to record failed-login event', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function handleLogout(Logout $event): void
    {
        try {
            LoginHistory::create([
                'user_id'    => $event->user?->getAuthIdentifier(),
                'identity'   => null,
                'event'      => 'logout',
                'ip_address' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'session_id' => $this->sessionId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('login_history: failed to record logout event', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function ip(): ?string
    {
        try {
            return request()?->ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function userAgent(): ?string
    {
        try {
            $ua = request()?->userAgent();
            // Truncate defensively; the column is TEXT, but some old
            // MySQL/MariaDB drivers would still complain past 65 KB, and
            // there's no reason to store more than that anyway.
            return $ua ? Str::limit($ua, 1000, '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function sessionId(): ?string
    {
        try {
            // hasSession() guards against non-web contexts (queue, cli).
            if (request()?->hasSession()) {
                return request()->session()->getId();
            }
        } catch (Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * Fortify's login form posts a single `identity` field (see
     * resources/views/auth/login.blade.php).  Everywhere else the standard
     * Laravel/Fortify convention is `email`, so fall back to that too.
     */
    private function identityFromRequest(): ?string
    {
        try {
            $req = request();
            if (!$req) {
                return null;
            }

            $identity = $req->input('identity') ?? $req->input('email') ?? $req->input('username');

            return $identity ? Str::limit(trim((string) $identity), 190, '') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Only used as a fallback when the Failed event is fired outside a
     * request context (unlikely, but Laravel does dispatch it from Guard
     * internals in a few edge cases).
     */
    private function identityFromCredentials(array $credentials): ?string
    {
        $value = $credentials['identity']
            ?? $credentials['email']
            ?? $credentials['username']
            ?? null;

        return $value ? Str::limit(trim((string) $value), 190, '') : null;
    }
}
