<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\AcceptMassIntentionRequest;
use Modules\MassIntentions\Http\Requests\BulkMoveMassIntentionRequest;
use Modules\MassIntentions\Http\Requests\MoveMassIntentionRequest;
use Modules\MassIntentions\Http\Requests\RequestMassIntentionClarificationRequest;
use Modules\MassIntentions\Http\Requests\RecordMassOfferingReceiptRequest;
use Modules\MassIntentions\Http\Requests\ScheduleMassIntentionRequest;
use Modules\MassIntentions\Http\Requests\StoreMassIntentionRequest;
use Modules\MassIntentions\Http\Requests\UpdateMassIntentionRequest;
use Modules\MassIntentions\Services\MassIntentionOfferingService;
use Modules\MassIntentions\Services\MassIntentionOfficeCloseService;
use Modules\MassIntentions\Services\MassIntentionOfficeRegisterPdfExportService;
use Modules\MassIntentions\Services\MassIntentionAssignmentService;
use Modules\MassIntentions\Services\MassIntentionRequestHistoryService;
use Modules\MassIntentions\Services\MassIntentionRequestListQuery;
use Modules\MassIntentions\Services\MassIntentionRequestService;
use Modules\MassIntentions\Services\MassIntentionSchedulingService;
use Modules\MassIntentions\Services\MassIntentionsDashboardService;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionRequestController extends Controller
{
    public function __construct(
        private readonly MassIntentionRequestService $requests,
        private readonly MassIntentionSchedulingService $scheduling,
        private readonly MassIntentionsDashboardService $dashboard,
        private readonly MassIntentionOfferingService $offerings,
        private readonly MassIntentionOfficeCloseService $officeClose,
        private readonly MassIntentionRequestListQuery $listQuery,
        private readonly MassIntentionOfficeRegisterPdfExportService $registerPdf,
        private readonly MassIntentionAssignmentService $assignments,
        private readonly MassIntentionRequestHistoryService $requestHistory,
    ) {
    }

    public function move(MoveMassIntentionRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->assignments->moveRequest(
            $this->tenantId(),
            $this->actor(),
            $id,
            (string) $validated['target_celebration_id'],
            $validated['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Intention moved to the selected Mass.',
            'data' => $this->requests->toArray($this->requests->findForTenant($this->tenantId(), $id)),
            'meta' => $result,
        ]);
    }

    public function bulkMove(BulkMoveMassIntentionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->assignments->bulkMoveRequests(
            $this->tenantId(),
            $this->actor(),
            $validated['intention_ids'],
            (string) $validated['target_celebration_id'],
            $validated['reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => $result['moved_count'] === 1
                ? 'Intention moved to the selected Mass.'
                : "{$result['moved_count']} intentions moved to the selected Mass.",
            'meta' => $result,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->officeClose->closeExpiredForTenant($tenantId);
        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        $builder = $this->listQuery->forTenant($request, $tenantId);

        $page = $builder->paginate($perPage);
        $this->requests->preloadMassCelebrationsForList($tenantId, $page->items());

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($item) => $this->requests->toArray($item))->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
        ]);
    }

    public function exportRegisterPdf(Request $request): Response
    {
        $tenantId = $this->tenantId();
        $this->officeClose->closeExpiredForTenant($tenantId);

        $rows = $this->listQuery->forTenant($request, $tenantId)->get();
        $this->requests->preloadMassCelebrationsForList($tenantId, $rows);
        if ($rows->isEmpty()) {
            throw new HttpException(404, 'No intentions match the current filters.');
        }

        $summary = $this->registerPdf->buildFilterSummaryLineFromRequest($request);
        $pdf = $this->registerPdf->renderPdf($rows, $summary);
        $filename = 'mass-intentions-'.now()->format('Y-m-d').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function store(StoreMassIntentionRequest $request): JsonResponse
    {
        $created = $this->requests->create($this->tenantId(), $this->actor(), $request->validated());
        $similar = $this->requests->findSimilar($this->tenantId(), $created);

        return response()->json([
            'success' => true,
            'message' => 'Intention saved.',
            'data' => $this->requests->toArray($created),
            'meta' => [
                'similar' => $similar->map(fn ($row) => $this->requests->toArray($row))->values(),
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->officeClose->closeExpiredForTenant($tenantId);
        $model = $this->requests->findForTenant($tenantId, $id);
        $model->load(['obligations', 'offering']);

        $meta = [
            'upcoming_celebrations' => $this->dashboard->upcomingCelebrations($tenantId),
            'history' => [
                'assignments' => $this->requestHistory->assignmentsForRequest($tenantId, $id),
                'audits' => $this->requestHistory->auditsForRequest($tenantId, $id),
            ],
        ];

        if ($this->actor()->hasPermission('mass.intentions.offerings.view')) {
            $meta['receipts'] = $this->offerings->listReceiptsForRequest($this->tenantId(), $id);
        }

        return response()->json([
            'success' => true,
            'data' => $this->requests->toArray($model),
            'meta' => $meta,
        ]);
    }

    public function recordReceipt(RecordMassOfferingReceiptRequest $request, string $id): JsonResponse
    {
        $receipt = $this->offerings->recordReceipt(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Payment recorded.',
            'data' => $receipt,
            'meta' => [
                'receipts' => $this->offerings->listReceiptsForRequest($this->tenantId(), $id),
            ],
        ], 201);
    }

    public function update(UpdateMassIntentionRequest $request, string $id): JsonResponse
    {
        $updated = $this->requests->update($this->tenantId(), $this->actor(), $id, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Intention updated.',
            'data' => $this->requests->toArray($updated),
        ]);
    }

    public function accept(AcceptMassIntentionRequest $request, string $id): JsonResponse
    {
        $accepted = $this->requests->accept($this->tenantId(), $this->actor(), $id, $request->validated());
        $tenantId = $this->tenantId();
        $meta = [
            'upcoming_celebrations' => $this->dashboard->upcomingCelebrations($tenantId),
        ];
        if ($this->actor()->hasPermission('mass.intentions.offerings.view')) {
            $receipts = $this->offerings->listReceiptsForRequest($tenantId, $id);
            if ($receipts !== []) {
                $meta['last_receipt'] = $receipts[array_key_last($receipts)];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Intention accepted.',
            'data' => $this->requests->toArray($accepted),
            'meta' => $meta,
        ]);
    }

    public function requestClarification(RequestMassIntentionClarificationRequest $request, string $id): JsonResponse
    {
        $updated = $this->requests->requestClarification(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated('message')
        );

        return response()->json([
            'success' => true,
            'message' => 'Sent back for details.',
            'data' => $this->requests->toArray($updated),
        ]);
    }

    public function withdraw(string $id): JsonResponse
    {
        $updated = $this->requests->withdraw($this->tenantId(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Intention withdrawn.',
            'data' => $this->requests->toArray($updated),
        ]);
    }

    public function close(string $id): JsonResponse
    {
        $closed = $this->officeClose->closeManual($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Intention closed.',
            'data' => $this->requests->toArray($closed),
        ]);
    }

    public function schedule(ScheduleMassIntentionRequest $request, string $id): JsonResponse
    {
        $model = $this->requests->findForTenant($this->tenantId(), $id);
        $this->scheduling->assignNextObligation(
            $this->tenantId(),
            $this->actor(),
            $model,
            (string) $request->validated('celebration_id'),
            $request->validated('date_variance_reason')
        );

        $fresh = $this->requests->findForTenant($this->tenantId(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Scheduled for Mass.',
            'data' => $this->requests->toArray($fresh),
        ]);
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return (int) $tenantId;
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $user;
    }
}
