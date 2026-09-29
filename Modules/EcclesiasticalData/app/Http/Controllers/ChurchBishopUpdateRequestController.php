<?php

namespace Modules\EcclesiasticalData\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EcclesiasticalData\Http\Controllers\Concerns\HandlesEcclesiasticalResponses;
use Modules\EcclesiasticalData\Http\Requests\StoreChurchBishopUpdateRequest;
use Modules\EcclesiasticalData\Http\Requests\SubmitChurchBishopUpdateRequest;
use Modules\EcclesiasticalData\Http\Requests\UpdateChurchBishopUpdateRequest;
use Modules\EcclesiasticalData\Http\Requests\UploadChurchBishopUpdatePhotoRequest;
use Modules\EcclesiasticalData\Http\Resources\BishopUpdateRequestResource;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Services\BishopUpdateRequestService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Support\TenantContext;

class ChurchBishopUpdateRequestController extends Controller
{
    use HandlesEcclesiasticalResponses;

    public function __construct(
        private readonly BishopUpdateRequestService $requestService,
        private readonly DioceseLeadershipQueryService $leadershipQuery,
        private readonly TenantContext $tenantContext,
    ) {}

    public function leadership(): JsonResponse
    {
        return $this->handleEcclesiastical(function () {
            $tenantId = $this->tenantId();
            $dioceseId = $this->resolveDioceseId($tenantId);

            return $this->ecclesiasticalSuccess(
                $this->leadershipQuery->getCurrentLeadership($dioceseId),
                'Diocesan leadership retrieved successfully'
            );
        }, 'Failed to retrieve diocesan leadership', 422);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            $this->authorize('viewAny', BishopUpdateRequest::class);

            $tenantId = $this->tenantId();
            $requests = BishopUpdateRequest::query()
                ->where('tenant_id', $tenantId)
                ->with(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('created_at')
                ->paginate($this->boundedPerPage($request));

            return $this->ecclesiasticalSuccess([
                'data' => BishopUpdateRequestResource::collection($requests->items()),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ], 'Bishop update requests retrieved successfully');
        }, 'Failed to retrieve bishop update requests');
    }

    public function show(string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($id) {
            $updateRequest = $this->findTenantRequest($id);
            $this->authorize('view', $updateRequest);

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($updateRequest->load(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])),
                'Bishop update request retrieved successfully'
            );
        }, 'Bishop update request not found');
    }

    public function store(StoreChurchBishopUpdateRequest $request): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request) {
            $tenantId = $this->tenantId();
            $dioceseId = $this->resolveDioceseId($tenantId);
            $validated = $request->validated();

            $created = $this->requestService->createDraft(
                $tenantId,
                $dioceseId,
                BishopUpdateRequestType::from($validated['request_type']),
                $validated['proposed_bishop_data'],
                $validated['proposed_appointment_data'] ?? [],
                (int) $request->user()->id,
                $validated['target_bishop_id'] ?? null,
            );

            if (! empty($validated['supporting_information']) || ! empty($validated['source_reference']) || ! empty($validated['submission_notes'])) {
                $created->update([
                    'supporting_information' => $validated['supporting_information'] ?? null,
                    'source_reference' => $validated['source_reference'] ?? null,
                    'submission_notes' => $validated['submission_notes'] ?? null,
                ]);
            }

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($created->fresh(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])),
                'Bishop update request draft created successfully',
                201
            );
        }, 'Failed to create bishop update request', 422);
    }

    public function update(UpdateChurchBishopUpdateRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $tenantId = $this->tenantId();
            $validated = $request->validated();

            $updated = $this->requestService->updateDraft(
                $id,
                $tenantId,
                $validated,
                (int) $validated['version'],
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($updated->load(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])),
                'Bishop update request updated successfully'
            );
        }, 'Failed to update bishop update request', 422);
    }

    public function submit(SubmitChurchBishopUpdateRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $submitted = $this->requestService->submit(
                $id,
                $this->tenantId(),
                (int) $request->validated('version'),
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($submitted->load(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])),
                'Bishop update request submitted for review'
            );
        }, 'Failed to submit bishop update request', 422);
    }

    public function uploadPhoto(UploadChurchBishopUpdatePhotoRequest $request, string $id): JsonResponse
    {
        return $this->handleEcclesiastical(function () use ($request, $id) {
            $file = $request->file('image');
            if (! $file) {
                throw \Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException::validation(
                    'Please attach a bishop photo.'
                );
            }

            $updated = $this->requestService->uploadPendingPhoto(
                $id,
                $this->tenantId(),
                $file,
                (int) $request->user()->id,
            );

            return $this->ecclesiasticalSuccess(
                new BishopUpdateRequestResource($updated->load(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])),
                'Suggested bishop photo saved'
            );
        }, 'Failed to save suggested bishop photo', 422);
    }

    private function tenantId(): int
    {
        return (int) $this->tenantContext->effectiveTenantId();
    }

    private function resolveDioceseId(int $tenantId): int
    {
        $profile = ChurchProfile::query()->where('tenant_id', $tenantId)->first();

        if (! $profile?->archdiocese_id) {
            throw \Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException::validation(
                'Your church must be linked to a diocese before submitting bishop updates.'
            );
        }

        return (int) $profile->archdiocese_id;
    }

    private function findTenantRequest(string $id): BishopUpdateRequest
    {
        $request = BishopUpdateRequest::query()
            ->where('tenant_id', $this->tenantId())
            ->with(['diocese', 'targetBishop', 'submittedBy', 'reviewer'])
            ->find($id);

        if (! $request) {
            throw \Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException::notFound(
                'Bishop update request not found.'
            );
        }

        return $request;
    }
}
