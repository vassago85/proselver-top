<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOemAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isOem()) {
            abort(403, 'OEM access required.');
        }

        return $next($request);
    }
}
