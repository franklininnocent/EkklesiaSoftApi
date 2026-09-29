<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Resources\BishopUpdateRequestResource;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Policies\Concerns\AuthorizesEcclesiasticalPermission;
use Modules\EcclesiasticalData\Services\BishopUpdateRequestService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;

class AdminBishopUpdateRequestController extends Controller
{
    use AuthorizesEcclesiasticalPermission;
    use HandlesEcclesiasticalResponses;

    public function __construct(
        private readonly BishopUpdateRequestService $requestService,
        private readonly DioceseLeadershipQueryService $leadershipQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            if (! $this->allowsPlatform($request->user(), 'bishops.review_requests')) {
                abort(403, 'Forbidden.');
            }

            $requests = BishopUpdateRequest::withoutTenantScope()
                ->with(['diocese', 'targetBishop', 'tenant'])
                ->when($request->query('status'), function ($q, $status) {
                    $statuses = $status === 'pending'
                        ? ['submitted', 'under_review']
                        : array_values(array_filter(explode(',', (string) $status)));

                    if (count($statuses) === 1) {
                        $q->where('status', $statuses[0]);
                    } elseif ($statuses !== []) {
                        $q->whereIn('status', $statuses);
                    }
                })
                ->when($request->query('tenant_id'), fn ($q, $tenantId) => $q->where('tenant_id', $tenantId))
                ->when($request->query('diocese_id'), fn ($q, $dioceseId) => $q->where('diocese_id', $dioceseId))
                ->orderByDesc('submitted_at')
                ->orderByDesc('created_at')
                ->paginate($this->boundedPerPage($request));

            return $this->ecclesiasticalSuccess([
                'data' => BishopUpdateRequestResource::collection($requests->items()),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ], 'Review queue retrieved successfully');
        }, 'Failed to retrieve review queue');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $updateRequest = BishopUpdateRequest::withoutTenantScope()
                ->with(['diocese', 'targetBishop', 'tenant'])
                ->findOrFail($id);

            $this->authorize('view', $updateRequest);

            $currentLeadership = $this->leadershipQuery->getCurrentLeadership((int) $updateRequest->diocese_id);

            return $this->ecclesiasticalSuccess([
                'request' => new BishopUpdateRequestResource($updateRequest),
                'diff' => $this->buildDiff($updateRequest, $currentLeadership),
            ], 'Review request retrieved successfully');
        }, 'Review request not found');
    }

    public function markUnderReview(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $updateRequest = BishopUpdateRequest::withoutTenantScope()->findOrFail($id);
            $this->authorize('view', $updateRequest);

            $marked = $this->requestService->markUnderReview($id, (int) $request->user()->id);

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($marked->load(['diocese', 'targetBishop', 'tenant'])),
                'Request marked under review'
            );
        }, 'Failed to mark request under review', 422);
    }

    public function requestClarification(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $validated = $request->validate([
                'submitter_feedback' => ['required', 'string', 'max:2000'],
                'internal_reviewer_notes' => ['nullable', 'string', 'max:2000'],
            ]);

            $updateRequest = BishopUpdateRequest::withoutTenantScope()->findOrFail($id);
            $this->authorize('requestClarification', $updateRequest);

            $updated = $this->requestService->requestClarification(
                $id,
                $validated['submitter_feedback'],
                (int) $request->user()->id,
                $validated['internal_reviewer_notes'] ?? null,
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($updated->load(['diocese', 'targetBishop', 'tenant'])),
                'Clarification requested'
            );
        }, 'Failed to request clarification', 422);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'max:2000'],
                'version' => ['required', 'integer', 'min:1'],
            ]);

            $updateRequest = BishopUpdateRequest::withoutTenantScope()->findOrFail($id);
            $this->authorize('reject', $updateRequest);

            $rejected = $this->requestService->reject(
                $id,
                $validated['reason'],
                (int) $request->user()->id,
                (int) $validated['version'],
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($rejected->load(['diocese', 'targetBishop', 'tenant'])),
                'Request rejected'
            );
        }, 'Failed to reject request', 422);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $validated = $request->validate([
                'version' => ['required', 'integer', 'min:1'],
            ]);

            $updateRequest = BishopUpdateRequest::withoutTenantScope()->findOrFail($id);
            $this->authorize('approve', $updateRequest);

            $applied = $this->requestService->approveAndApply(
                $id,
                (int) $validated['version'],
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($applied->load(['diocese', 'targetBishop', 'tenant'])),
                'Request approved and applied'
            );
        }, 'Failed to approve request', 422);
    }

    /**
     * @param  array<string, mixed>  $currentLeadership
     * @return array<string, mixed>
     */
    private function buildDiff(BishopUpdateRequest $updateRequest, array $currentLeadership): array
    {
        $currentBishop = null;
        if ($updateRequest->relationLoaded('targetBishop') && $updateRequest->targetBishop) {
            $currentBishop = $updateRequest->targetBishop;
        } elseif ($updateRequest->target_bishop_id) {
            $currentBishop = BishopManagement::query()
                ->select(['id', 'full_name', 'email', 'phone', 'status', 'photo_path', 'photo_url'])
                ->find($updateRequest->target_bishop_id);
        } elseif ($currentLeadership['ordinary']) {
            $currentBishop = BishopManagement::query()
                ->select(['id', 'full_name', 'email', 'phone', 'status', 'photo_path', 'photo_url'])
                ->find($currentLeadership['ordinary']['bishop_id']);
        }

        $uploadService = app(\Modules\EcclesiasticalData\Services\BishopFileUploadService::class);
        $proposed = $updateRequest->proposed_bishop_data ?? [];
        $pendingPhotoPath = is_string($proposed['pending_photo_path'] ?? null)
            ? $proposed['pending_photo_path']
            : null;

        return [
            'current_leadership' => $currentLeadership,
            'current_bishop' => $currentBishop ? [
                'id' => $currentBishop->id,
                'full_name' => $currentBishop->full_name,
                'email' => $currentBishop->email,
                'phone' => $currentBishop->phone,
                'status' => $currentBishop->status,
                'photo_public_url' => $uploadService->resolvePhotoUrl(
                    $currentBishop->photo_path,
                    $currentBishop->photo_url,
                ),
            ] : null,
            'proposed_bishop' => $proposed,
            'proposed_photo_public_url' => $uploadService->publicUrl($pendingPhotoPath),
            'proposed_appointment' => $updateRequest->proposed_appointment_data,
            'request_type' => $updateRequest->request_type?->value ?? $updateRequest->request_type,
            'potential_duplicate_bishop_ids' => $updateRequest->proposed_bishop_data['_potential_duplicate_bishop_ids'] ?? [],
        ];
    }
}
