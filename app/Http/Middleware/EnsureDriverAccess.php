<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isDriver()) {
            abort(403, 'Driver access required.');
        }

        return $next($request);
    }
}
