<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isInternal()) {
            abort(403, 'Internal access required.');
        }

        return $next($request);
    }
}
