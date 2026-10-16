<?php

namespace Modules\Tenants\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Tenants\Services\PlatformAuditLogger;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class LogPlatformSecurityEvents
{
    public function __construct(
        private readonly PlatformAuditLogger $auditLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if (! config('tenants.platform.security_audit.enabled', true)) {
            return $response;
        }

        if (! $request->is('api/*') || ! in_array($status, [401, 403], true)) {
            return $response;
        }

        $tenantId = null;
        try {
            $tenantId = app(TenantContext::class)->effectiveTenantId();
        } catch (\Throwable) {
            $tenantId = $request->user()?->tenant_id;
        }

        $event = $status === 401 ? 'auth_failure' : 'authorization_denied';

        $this->auditLogger->record(
            category: 'security',
            event: $event,
            tenantId: $tenantId !== null ? (int) $tenantId : null,
            actorUserId: $request->user()?->id ? (int) $request->user()->id : null,
            httpStatus: $status,
            requestMethod: $request->method(),
            requestPath: '/'.ltrim($request->path(), '/'),
            metadata: [
                'route' => $request->route()?->getName(),
            ],
        );

        return $response;
    }
}
