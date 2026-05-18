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

        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            return $next($request);
        }

        abort(403, 'Customer access required.');
    }
}
