<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Customer access required.');
        }

        if ($user->isDeveloper()) {
            return $next($request);
        }

        // Body-builder tenants share the customer-tier role slugs and
        // genuinely need /customer/team + /customer/locations (those
        // pages are tenant-scoped on $user->company(), so they manage
        // BB users / workshops correctly).  The sidebar renders the
        // BB-only branch for these users, so they won't see customer
        // ordering pages even though they technically can reach them.
        if ($user->companyIsBodyBuilder()) {
            return $next($request);
        }

        // Every modern tenant — dealer-customers, OEM-customers, body
        // builders — sits on a customer-tier role.  The /dealer/* and
        // /oem/* portals were retired; OEM-vs-dealer differentiation
        // now lives on Company::$type and is enforced inside the
        // customer Volt pages themselves.
        //
        // We also accept legacy dealer-tier and oem-tier ROLE users
        // (dealer_owner, dealer_admin, oem_owner, oem_admin, ...) so
        // any tenant that hasn't been re-seeded onto a customer-tier
        // role can still reach their dashboard.  Without this branch
        // an authenticated dealer-tier user would loop indefinitely
        // between /dashboard and /customer/dashboard.
        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            return $next($request);
        }

        abort(403, 'Customer access required.');
    }
}
