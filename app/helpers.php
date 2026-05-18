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
        // Body-builder tenants land on their dedicated portal regardless
        // of the underlying customer-tier role they hold (BB owner / BB
        // user both have customer-tier slugs so $user->isCustomer() is
        // also true for them — must come BEFORE the customer branch).
        if (method_exists($user, 'companyIsBodyBuilder') && $user->companyIsBodyBuilder()) {
            return route('body-builder.dashboard');
        }
        if ($user->isCustomer()) {
            return route('customer.dashboard');
        }
        if ($user->isDriver()) {
            return route('driver.dashboard');
        }

        // Legacy dealer-tier / oem-tier role users (dealer_owner,
        // dealer_admin, oem_owner, oem_admin, ...) no longer have a
        // dedicated portal — the /dealer/* and /oem/* prefixes were
        // retired with no production tenants on them.  Anything that
        // still holds one of those role slugs falls through to login
        // and will need to be re-seeded onto a customer-tier role.
        return route('login');
    }
}
