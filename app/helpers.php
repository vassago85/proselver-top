<?php

/**
 * Global helpers autoloaded via composer.
 *
 * IMPORTANT: functions defined here must be usable from cached route
 * closures (php artisan route:cache serialises closures, so any helper
 * they reference must live in an autoloaded file — NOT inline in
 * routes/web.php).
 */

if (!function_exists('resolveInternalDashboardRoute')) {
    /**
     * Which of the three internal dashboards a user belongs on.
     *
     * The internal dashboard is split three ways -- Operations (live
     * pipeline), Finance (invoicing / petty cash / driver pay) and the
     * Owner roll-up.  Each internal role has one natural home:
     *
     *   accounts                     -> Finance
     *   owner / super_admin / dev    -> Owner roll-up (links to both)
     *   ops controller / dispatcher  -> Operations
     *
     * This is the single source of truth: both the post-login redirect
     * and the /admin/dashboard compatibility route call it, so they can
     * never disagree.  Returns a route NAME, not a URL, so callers can
     * decide between route() and redirect()->route().
     */
    function resolveInternalDashboardRoute($user): string
    {
        // Developer is checked first and separately from hasRole() because
        // developers can role-switch in the dev toolbar; when they've
        // switched we want the switched role's dashboard, but an unswitched
        // developer should see the owner roll-up rather than raw ops.
        if ($user->isAccounts()) {
            return 'admin.dashboard.finance';
        }

        if ($user->isOwner() || $user->isSuperAdmin() || $user->isDeveloper()) {
            return 'admin.dashboard.owner';
        }

        return 'admin.dashboard.ops';
    }
}

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
            return route(resolveInternalDashboardRoute($user));
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

if (!function_exists('tenantRoleDisplayName')) {
    /**
     * Display label for a customer-tier role name, adjusted for the
     * tenant's company type.  All customer-tier roles share the same
     * underlying slugs (customer_owner / customer_admin / customer_user
     * / customer_dispatcher) and are seeded with "Customer X" names,
     * but dealers and OEMs want their portal to read "Dealer X" /
     * "OEM X".  This is presentation-only -- slugs and permissions are
     * unchanged.
     *
     * Centralised here so the user-menu header, profile page, and team
     * page all relabel the same way.  $companyType is the
     * Company::TYPE_* constant of the user's primary company (null is
     * tolerated: returns the seeded name as-is).
     */
    function tenantRoleDisplayName(string $roleName, ?string $companyType = null): string
    {
        return match ($companyType) {
            \App\Models\Company::TYPE_OEM    => str_replace('Customer ', 'OEM ', $roleName),
            \App\Models\Company::TYPE_DEALER => str_replace('Customer ', 'Dealer ', $roleName),
            default                          => $roleName,
        };
    }
}
