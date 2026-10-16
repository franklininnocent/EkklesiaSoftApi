<?php

namespace Modules\Subscriptions\Http\Controllers\Tenant;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Subscriptions\Http\Requests\Tenant\SubmitUpgradeRequest;
use Modules\Subscriptions\Models\SubscriptionUpgradeRequest;
use Modules\Subscriptions\Services\SubscriptionAuditService;
use Modules\Subscriptions\Services\UpgradeRequestService;
use Modules\Subscriptions\Support\TenantSubscriptionAccess;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * The signed-in church's own plan requests. The tenant always comes from TenantContext.
 */
class TenantUpgradeRequestController extends Controller
{
    public function __construct(
        private readonly UpgradeRequestService $requests,
        private readonly SubscriptionAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return $this->noTenant();
        }
        if (! TenantSubscriptionAccess::canView($request->user())) {
            return $this->forbidden($tenant);
        }

        $rows = SubscriptionUpgradeRequest::query()
            ->where('tenant_id', $tenant->id)
            ->with(['requestedPlan', 'currentPlan', 'requester', 'reviewer'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (SubscriptionUpgradeRequest $r) => $this->requests->present($r))
            ->all();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(SubmitUpgradeRequest $request): JsonResponse
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return $this->noTenant();
        }
        if (! TenantSubscriptionAccess::canView($request->user())) {
            return $this->forbidden($tenant);
        }

        $created = $this->requests->submit($tenant, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Your request has been sent to Ekklesia. We will let you know once it is reviewed.',
            'data' => $this->requests->present($created),
        ], 201);
    }

    private function tenant(): ?Tenant
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return $tenantId ? Tenant::query()->find($tenantId) : null;
    }

    private function forbidden(Tenant $tenant): JsonResponse
    {
        $this->audit->denial('upgrade_request_unauthorized', (int) $tenant->id);

        return response()->json(['success' => false, 'message' => 'Only church administrators can request plan changes.'], 403);
    }

    private function noTenant(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'User is not associated with a tenant/church'], 404);
    }
}
