<?php

namespace Modules\Subscriptions\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Services\LimitEnforcementService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for single-record create endpoints: `entitlement.limit:PEOPLE_LIMIT[,adding]`.
 * Multi-record or transactional flows call LimitEnforcementService inside their transaction.
 */
class EnsureWithinLimit
{
    public function __construct(private readonly LimitEnforcementService $limits) {}

    public function handle(Request $request, Closure $next, string $featureCode, string $adding = '1'): Response
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant) {
                try {
                    $this->limits->assertCanAdd($tenant, $featureCode, max(1, (int) $adding));
                } catch (SubscriptionException $e) {
                    return $e->render();
                }
            }
        }

        return $next($request);
    }
}
