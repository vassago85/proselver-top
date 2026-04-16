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

        if ($user->isCustomer() || $user->isDealer() || $user->isOem()) {
            return $next($request);
        }

        abort(403, 'Customer access required.');
    }
}
