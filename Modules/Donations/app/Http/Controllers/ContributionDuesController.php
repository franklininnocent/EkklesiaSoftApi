<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreDueRequest;
use Modules\Donations\Http\Requests\UpdateDueStatusRequest;
use Modules\Donations\Jobs\SendContributionReminderJob;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Services\ContributionDueService;
use Modules\Donations\Services\DonationAuditService;
use Modules\Donations\Support\ContributionBalance;

class ContributionDuesController extends Controller
{
    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly ContributionDueService $dueService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

        $query = ContributionDue::forTenant($tenantId)->with(['family', 'plan']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('family_id')) {
            $query->where('family_id', $request->string('family_id'));
        }
        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->string('plan_id'));
        }
        if ($request->boolean('overdue_only')) {
            $query->whereDate('due_date', '<', now()->toDateString())
                ->whereIn('status', ['pending', 'partially_paid']);
        }

        $paginator = $query->orderBy('due_date')->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(function (ContributionDue $due): ContributionDue {
            $due->setAttribute('outstanding_amount', ContributionBalance::outstandingForDue($due));

            return $due;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function store(StoreDueRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['amount_paid'] = 0;
        $payload['status'] = 'pending';

        $due = ContributionDue::create($payload);
        $this->auditService->log($tenantId, 'due.created', 'due', $due->id, null, $due->toArray());
        SendContributionReminderJob::dispatch($tenantId, $due->id);

        return response()->json([
            'success' => true,
            'message' => 'Due created successfully.',
            'data' => $due,
        ], 201);
    }

    public function waive(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);

        if (!in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid dues can be waived.',
            ], 422);
        }

        $due = $this->dueService->waiveDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Due waived successfully.',
            'data' => $due,
        ]);
    }

    public function cancel(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);

        if (!in_array($due->status, ['pending', 'partially_paid'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending or partially paid dues can be cancelled.',
            ], 422);
        }

        $due = $this->dueService->cancelDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Due cancelled successfully.',
            'data' => $due,
        ]);
    }

    public function sendReminder(string $id): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $due = ContributionDue::forTenant($tenantId)->findOrFail($id);

        SendContributionReminderJob::dispatch($tenantId, $due->id);

        return response()->json([
            'success' => true,
            'message' => 'Contribution reminder queued.',
        ]);
    }
}
