<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StorePlanAssignmentRequest;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanAssignment;
use Modules\Donations\Services\ContributionPlanService;

class ContributionPlanAssignmentsController extends Controller
{
    public function __construct(private readonly ContributionPlanService $planService)
    {
    }

    public function index(string $planId, Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        ContributionPlan::forTenant($tenantId)->findOrFail($planId);

        $assignments = ContributionPlanAssignment::forTenant($tenantId)
            ->where('plan_id', $planId)
            ->with('family')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('effective_from')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    public function store(string $planId, StorePlanAssignmentRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $plan = ContributionPlan::forTenant($tenantId)->findOrFail($planId);
        $payload = $request->validated();

        $this->planService->syncAssignments($tenantId, $userId, $plan, [$payload]);

        $assignment = ContributionPlanAssignment::forTenant($tenantId)
            ->where('plan_id', $planId)
            ->where('family_id', $payload['family_id'])
            ->whereDate('effective_from', $payload['effective_from'])
            ->with('family')
            ->latest('created_at')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Family assignment saved successfully.',
            'data' => $assignment,
        ], 201);
    }
}
