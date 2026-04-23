<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limits the password-reset endpoints that Fortify registers.
 *
 * Without this, `POST /forgot-password` and `POST /reset-password` have no
 * throttle (the project only defined a `login` limiter), which lets an
 * attacker email-bomb any address, enumerate valid accounts, or brute the
 * reset token within its 60-minute expiry window.
 *
 * Keyed by email + IP so a single attacker can't quietly burn through the
 * limit by targeting many different inboxes, while legitimate users on shared
 * office IPs aren't punished by a noisy neighbour. A low-and-noisy 5 per
 * minute is tight enough to stop automation without frustrating humans who
 * mistype their password twice.
 */
class ThrottlePasswordReset
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('post')) {
            return $next($request);
        }

        if (!$request->is('forgot-password') && !$request->is('reset-password')) {
            return $next($request);
        }

        $email = Str::lower(trim((string) $request->input('email', '')));
        $key = 'pwreset:' . ($email !== '' ? $email : 'anon') . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => "Too many password reset attempts. Please wait {$seconds} seconds and try again.",
                ]);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
