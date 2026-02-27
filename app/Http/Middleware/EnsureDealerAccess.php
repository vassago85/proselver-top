<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDealerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isDealer()) {
            abort(403, 'Dealer access required.');
        }

        return $next($request);
    }
}
