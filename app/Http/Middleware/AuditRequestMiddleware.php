<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditRequestMiddleware
{
    protected array $auditedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), $this->auditedMethods) && $request->user()) {
            try {
                AuditLog::create([
                    'actor_user_id' => $request->user()->id,
                    'actor_roles_snapshot' => implode(',', $request->user()->getRoleNames()),
                    'action_type' => 'http_request',
                    'entity_type' => 'request',
                    'entity_id' => null,
                    'after_json' => [
                        'method' => $request->method(),
                        'url' => $request->fullUrl(),
                        'route' => $request->route()?->getName(),
                        'status' => $response->getStatusCode(),
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
