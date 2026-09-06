<?php

namespace Modules\Donations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Donations\Services\DonationSecurityEventService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class LogDonationSecurityResponse
{
    public function __construct(private readonly DonationSecurityEventService $securityEvents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if (! in_array($status, [401, 403, 404], true)) {
            return $response;
        }

        $event = match ($status) {
            401 => 'unauthenticated',
            403 => 'rbac_denied',
            default => 'not_found_or_idor',
        };

        $tenantId = null;
        try {
            $tenantId = app(TenantContext::class)->effectiveTenantId();
        } catch (\Throwable) {
            $tenantId = $request->user()?->tenant_id;
        }

        $this->securityEvents->record(
            $event,
            $tenantId ? (int) $tenantId : null,
            null,
            null,
            $status,
            ['query' => $request->query()]
        );

        return $response;
    }
}
