<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Developers get the admin portal for free — but only when they
        // are actually acting as themselves. The dev toolbar's "View as"
        // switch (session key `dev_role_override`) makes them pretend to
        // be another role for the sidebar and every permission check;
        // if we still let them through this gate on their real developer
        // badge they'd end up staring at every customer's data on
        // /admin/orders while their sidebar swore they were a driver.
        // Fall through to the effective-role check in that case.
        if ($user?->isDeveloper() && !session('dev_role_override')) {
            return $next($request);
        }

        if (!$user?->isInternal()) {
            abort(403, 'Internal access required.');
        }

        return $next($request);
    }
}
