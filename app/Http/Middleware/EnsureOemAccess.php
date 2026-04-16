<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOemAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isDeveloper()) {
            return $next($request);
        }

        if (!$user?->isOem()) {
            abort(403, 'OEM access required.');
        }

        return $next($request);
    }
}
