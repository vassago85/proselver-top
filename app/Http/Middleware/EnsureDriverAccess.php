<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverAccess
{
    /**
     * Gate the Driver PWA (/driver/*) to users with the 'driver' role.
     *
     * The PWA's UI (trade plate, licence, IndexedDB per driver, photo sync
     * queues) assumes the signed-in user IS a driver and has a driver
     * profile attached. We intentionally do NOT allow generic ops/owner
     * logins through the PWA — if an ops user needs to move a vehicle
     * themselves, they should be given a separate driver account (the
     * RBAC layer supports a single person holding multiple roles; they
     * just sign into the PWA with their driver login).
     *
     * Behaviour:
     *  - Developer role: always allowed (for support/debugging).
     *  - Driver role: allowed.
     *  - Anyone else who is signed in: redirected back to their own
     *    role-appropriate home with a flash message explaining what
     *    happened and offering a sign-out. This replaces the old
     *    dead-end 403 the user hit when they clicked a /driver/... link
     *    while signed in as owner/ops.
     *  - Not signed in at all: Laravel's `auth` middleware runs BEFORE
     *    this one and will have already redirected to /login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isDeveloper() || $user?->isDriver()) {
            return $next($request);
        }

        // Signed in but not a driver. Bounce them back to their own
        // workspace rather than slamming a blank 403 in their face.
        $home = function_exists('resolveUserHomePath')
            ? resolveUserHomePath($user)
            : route('login');

        $message = 'The Driver app is for drivers only. You are signed in as ' .
            ($user?->name ?: 'a non-driver user') .
            '. Sign out and sign in with the driver account to open the PWA.';

        return redirect()->to($home)->with('pwa_access_denied', $message);
    }
}
