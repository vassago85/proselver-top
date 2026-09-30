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
     * Which internal landing route a user gets after login.
     *
     * Historically split three ways -- Operations, Finance, Owner --
     * with ops / dispatch / super_admin landing on Operations.  Since
     * the 2026-09-30 staff-request nav cut, the Operations dashboard
     * is hidden from ops / dispatch / super_admin / accounts and only
     * owner + developer see it.  Ops staff asked to land on the
     * Orders index instead, where they actually spend their shift.
     *
     *   accounts                     -> Finance
     *   owner / developer            -> Owner command centre
     *   super_admin / ops controller
     *     / dispatcher               -> Orders index (was Ops dash)
     *
     * This is the single source of truth: both the post-login redirect
     * and the /admin/dashboard compatibility route call it, so they can
     * never disagree.  Returns a route NAME, not a URL, so callers can
     * decide between route() and redirect()->route().
     */
    function resolveInternalDashboardRoute($user): string
    {
        if ($user->isAccounts()) {
            return 'admin.dashboard.finance';
        }

        // Developer is checked here (rather than only via isDeveloper())
        // because an unswitched developer shares the owner's command
        // centre.  A developer with the dev toolbar switched to another
        // role (session key `dev_role_override`) should get THAT role's
        // dashboard — isOwner() already reads effectiveRoles(), so the
        // switch flows naturally.  We only fall through to the real
        // developer badge here when no override is active.
        if ($user->isOwner() || ($user->isDeveloper() && !session('dev_role_override'))) {
            return 'admin.dashboard.owner';
        }

        // Ops controller / dispatcher / super_admin -- their dashboard
        // link was removed on 2026-09-30 (staff request); land them on
        // Orders where they actually run their shift.
        return 'admin.orders.index';
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

        // isDeveloper() reads the REAL roles (not the dev toolbar's
        // effective override) on purpose, so an unswitched developer
        // still gets an internal home.  When they've flipped the "View
        // as" switch we skip this branch and honour the effective role
        // below — otherwise a "View as Driver" session would still
        // resolve to /admin/dashboard/owner and blow past the driver
        // PWA's middleware on the way in.
        if ($user->isInternal() || ($user->isDeveloper() && !session('dev_role_override'))) {
            return route(resolveInternalDashboardRoute($user));
        }
        // Body-builder tenants land on their dedicated portal regardless
        // of the underlying customer-tier role they hold (BB owner / BB
        // user both have customer-tier slugs so $user->isCustomer() is
        // also true for them — must come BEFORE the customer branch).
        if (method_exists($user, 'companyIsBodyBuilder') && $user->companyIsBodyBuilder()) {
            return route('body-builder.dashboard');
        }
        // Driver PWA comes BEFORE the customer branch: a real driver
        // has no customer-tier roles, but a developer switched to
        // "View as Driver" also picks up isDriver() via effectiveRoles(),
        // and we want them on /driver/dashboard, not /customer/dashboard.
        if ($user->isDriver()) {
            return route('driver.dashboard');
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
