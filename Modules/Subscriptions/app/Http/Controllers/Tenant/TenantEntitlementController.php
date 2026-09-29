<?php

namespace Modules\Subscriptions\Http\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Subscriptions\Services\UsageService;
use Modules\Subscriptions\Support\TenantSubscriptionAccess;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * Tenant self-service reads. The tenant is always the authenticated effective tenant;
 * no tenant id is accepted from the request.
 */
class TenantEntitlementController extends Controller
{
    public function __construct(
        private readonly SubscriptionPresenter $presenter,
        private readonly EntitlementResolver $resolver,
        private readonly UsageService $usage,
    ) {}

    /**
     * Entitlement map for UI gating — any member of the tenant may read it.
     */
    public function entitlements(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return $this->noTenant();
        }

        return response()->json(['success' => true, 'data' => $this->presenter->entitlementMap($tenant)]);
    }

    /**
     * "My plan" overview including commercial terms — tenant administrators only.
     */
    public function subscription(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return $this->noTenant();
        }
        if (! $this->canViewSubscription($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to view subscription.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->presenter->tenantOverview($tenant)]);
    }

    public function usage(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return $this->noTenant();
        }
        if (! $this->canViewSubscription($request->user())) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to view subscription.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->usage->summary($this->resolver->resolve($tenant))]);
    }

    private function tenant(): ?Tenant
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return $tenantId ? Tenant::query()->find($tenantId) : null;
    }

    private function canViewSubscription(?User $user): bool
    {
        return TenantSubscriptionAccess::canView($user);
    }

    private function noTenant(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'User is not associated with a tenant/church'], 404);
    }
}
