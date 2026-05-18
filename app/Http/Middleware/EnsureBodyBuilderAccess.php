<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /body-builder/* portal.
 *
 * Allows through:
 *   - Users whose primary company is type=body_builder AND who hold a
 *     body_builder_owner / body_builder_user role.
 *   - Internal ProSelver staff (developer / super_admin / etc.) so they
 *     can shoulder-surf when supporting a body-builder tenant.
 *
 * Everyone else 403s.  The portal layout assumes the user has a BB
 * company attached; lifting that assumption would let role-less guests
 * see request queues across tenants.
 */
class EnsureBodyBuilderAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Body builder access required.');
        }

        // Internal ProSelver support — developer + super_admin + ops
        // can preview the BB portal to help triage requests.
        if ($user->isDeveloper() || $user->isSuperAdmin() || $user->isInternal()) {
            return $next($request);
        }

        $company = $user->company();
        if (! $company || $company->type !== Company::TYPE_BODY_BUILDER) {
            abort(403, 'Body builder access required.');
        }

        if (! $user->isBodyBuilderTenant()) {
            abort(403, 'Your role is not authorised for the body-builder portal.');
        }

        return $next($request);
    }
}
