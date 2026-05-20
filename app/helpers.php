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
        // Customer / dealer / OEM tenants all land on the customer
        // portal.  The /dealer/* and /oem/* portals were retired and
        // their tenants now share /customer/* (see EnsureCustomerAccess
        // for the matching middleware-level acceptance).  isCustomer is
        // tier=='customer' only, so legacy dealer_admin / oem_owner
        // users wouldn't otherwise resolve here -- without this branch
        // they'd be sent back to /login, which then re-redirects
        // /dashboard for an authenticated user => infinite loop.
        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            return route('customer.dashboard');
        }
        if ($user->isDriver()) {
            return route('driver.dashboard');
        }

        // Authenticated user with no resolvable home (e.g. a brand new
        // account whose role hasn't been assigned yet).  Sending them
        // to /login causes an infinite redirect because Fortify
        // bounces authenticated requests back to /dashboard.  Land on
        // the profile page instead so the user can at least see who
        // they're logged in as and contact ops.
        return route('profile.index');
    }
}
