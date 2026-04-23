<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces any authenticated user with `must_change_password = true` to rotate
 * their password before accessing anything else.
 *
 * Why: all 38 fleet drivers were seeded with the same shared password. A
 * single leaked password (or a driver lending their phone to a colleague)
 * compromises the entire fleet PWA. Admin-created internal users have a
 * similar exposure — the creator knows the initial password and there is
 * no audit trail of when (or whether) the account holder changed it.
 *
 * The middleware allows a narrow set of paths through so the user can
 * actually complete the rotation:
 *   - GET/POST /profile and /profile/password (the change-password form)
 *   - POST /logout (let them sign out if they don't want to proceed)
 *   - Livewire update endpoint (so the Volt form can submit over xhr)
 *   - /up and /manifest.webmanifest and /sw.js (health + PWA boot)
 *
 * Apply globally (via the `web` group) so it covers admin, dealer, oem,
 * customer AND driver routes in one place.
 */
class ForceChangePassword
{
    /**
     * Paths that remain reachable even when the user must change their
     * password. Keep this list minimal — every entry is an exception to a
     * security guard.
     */
    private const ALLOWED_PATHS = [
        'profile',
        'profile/*',
        'logout',
        'livewire/*',
        'up',
        'manifest.webmanifest',
        'manifest.json',
        'sw.js',
        'driver-sw.js',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->must_change_password) {
            return $next($request);
        }

        if ($request->is(self::ALLOWED_PATHS)) {
            return $next($request);
        }

        // Tell Livewire to do a hard redirect; otherwise a Livewire component
        // navigation stays inside the forbidden page and the user sees a
        // flash of protected content.
        if ($request->hasHeader('X-Livewire')) {
            return response()->json([
                'redirect' => route('profile.index') . '?must_change=1',
            ], 200);
        }

        return redirect()
            ->route('profile.index', ['must_change' => 1])
            ->with('warning', 'Please change your password before continuing.');
    }
}
