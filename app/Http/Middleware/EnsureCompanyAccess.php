<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->isInternal()) {
            return $next($request);
        }

        if ($user->isDealer() && $user->companies()->exists()) {
            return $next($request);
        }

        abort(403, 'No company access configured.');
    }
}
