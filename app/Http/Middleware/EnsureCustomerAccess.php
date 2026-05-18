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
        // builders — sits on a customer-tier role.  The legacy
        // isDealer() / isOem() ROLE checks (dealer_owner, oem_admin,
        // ...) were dropped when the /dealer/* and /oem/* portals
        // were retired; OEM-vs-dealer differentiation now lives on
        // Company::$type and is enforced inside the customer Volt
        // pages themselves.
        if ($user->isCustomer()) {
            return $next($request);
        }

        abort(403, 'Customer access required.');
    }
}
