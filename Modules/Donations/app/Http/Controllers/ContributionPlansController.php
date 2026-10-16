<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Donations\Exceptions\ScheduleGenerationBusyException;
use Modules\Donations\Http\Requests\GenerateContributionDuesRequest;
use Modules\Donations\Http\Requests\StoreContributionPlanRequest;
use Modules\Donations\Http\Requests\UpdateContributionPlanRequest;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\ContributionPlanRevisionHistory;
use Modules\Donations\Services\ContributionDueService;
use Modules\Donations\Services\ContributionPlanService;
use Modules\Donations\Support\ContributionPeriod;
use Modules\Donations\Support\DonationBusinessDate;

class ContributionPlansController extends Controller
{
    public function __construct(
        private readonly ContributionPlanService $planService,
        private readonly ContributionDueService $dueService
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        $plans = ContributionPlan::forTenant($tenantId)
            ->with('fund')
            ->withCount(['assignments', 'dues'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        $plan = ContributionPlan::forTenant($tenantId)
            ->with(['fund', 'assignments.family'])
            ->withCount('dues')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $plan,
        ]);
    }

    public function store(StoreContributionPlanRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        $plan = $this->planService->create($tenantId, $userId, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Contribution plan created successfully.',
            'data' => $plan,
        ], 201);
    }

    public function update(string $id, UpdateContributionPlanRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $plan = ContributionPlan::forTenant($tenantId)->findOrFail($id);

        $plan = $this->planService->update($tenantId, $userId, $plan, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Contribution plan updated successfully.',
            'data' => $plan,
        ]);
    }

    public function revisionHistory(string $id, Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        ContributionPlan::forTenant($tenantId)->findOrFail($id);

        $history = ContributionPlanRevisionHistory::query()
            ->where('tenant_id', $tenantId)
            ->where('plan_id', $id)
            ->when($request->filled('family_id'), fn ($q) => $q->where('family_id', $request->string('family_id')))
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    public function generationPreview(string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $plan = ContributionPlan::forTenant($tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->dueService->previewSchedule($plan),
        ]);
    }

    public function generateDues(string $id, GenerateContributionDuesRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $plan = ContributionPlan::forTenant($tenantId)->findOrFail($id);
        $payload = $request->validated();

        try {
            if (!empty($payload['generate_full_schedule'])) {
                $result = $this->dueService->generateSchedule(
                    $tenantId,
                    $userId,
                    $plan,
                    $payload['family_ids'] ?? null
                );

                if (($result['status'] ?? null) === 'queued') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Schedule generation queued for background processing.',
                        'data' => $result,
                    ], 202);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Contribution schedule generated successfully.',
                    'data' => $result,
                ]);
            }

            if (!empty($payload['use_current_period'])) {
                $dues = $this->dueService->generateCurrentPeriod($tenantId, $userId, $plan);

                return response()->json([
                    'success' => true,
                    'message' => 'Current period dues generated successfully.',
                    'data' => $dues,
                ]);
            }

            $businessToday = DonationBusinessDate::today($tenantId);
            $period = ContributionPeriod::currentForPlan(
                $plan,
                \Carbon\Carbon::parse($businessToday)
            );
            $periodLabel = $payload['period_label'] ?? $period['period_label'];
            $dueDate = $payload['due_date'] ?? ContributionPeriod::applyGraceDays(
                $period['due_date'],
                (int) $plan->grace_days
            );

            $familyIds = $payload['family_ids'] ?? $this->dueService->getEnrolledFamilyIds($plan, $period['period_start'], $period['period_end']);

            $dues = $this->dueService->generateForFamilies(
                $tenantId,
                $userId,
                $plan,
                $familyIds,
                $periodLabel,
                $dueDate,
                isset($payload['amount_due']) ? (float) $payload['amount_due'] : null,
                $payload['notes'] ?? null,
                $period['period_start'],
                $period['period_end']
            );

            return response()->json([
                'success' => true,
                'message' => 'Contribution dues generated successfully.',
                'data' => $dues,
            ]);
        } catch (ScheduleGenerationBusyException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
