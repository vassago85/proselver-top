<?php

/**
 * Global helpers autoloaded via composer.
 *
 * IMPORTANT: functions defined here must be usable from cached route
 * closures (php artisan route:cache serialises closures, so any helper
 * they reference must live in an autoloaded file — NOT inline in
 * routes/web.php).
 */

if (!function_exists('resolveUserHomePath')) {
    /**
     * Return the post-login home URL for a given user based on their role.
     */
    function resolveUserHomePath($user): string
    {
        if (!$user) {
            return route('login');
        }

        if ($user->isInternal() || $user->isDeveloper()) {
            return route('admin.dashboard');
        }
        if ($user->isCustomer()) {
            return route('customer.dashboard');
        }
        if ($user->isDealer()) {
            return route('dealer.dashboard');
        }
        if ($user->isOem()) {
            return route('oem.dashboard');
        }
        if ($user->isDriver()) {
            return route('driver.dashboard');
        }

        return route('login');
    }
}
