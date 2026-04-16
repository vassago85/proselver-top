<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isDeveloper()) {
            return $next($request);
        }

        if (!$user?->isDriver()) {
            abort(403, 'Driver access required.');
        }

        return $next($request);
    }
}
