<?php

namespace Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Tenants\Exceptions\ChurchLeadershipDomainException;
use Modules\Tenants\Http\Requests\AssignLeadershipRequest;
use Modules\Tenants\Http\Requests\HandoverLeadershipRequest;
use Modules\Tenants\Http\Requests\TerminateLeadershipRequest;
use Modules\Tenants\Http\Requests\UpdateLeadershipAssignmentRequest;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Services\LeadershipDomainService;
use Modules\Tenants\Support\LeadershipRoleCategory;
use Modules\Tenants\Support\TenantContext;

class ChurchLeadershipGovernanceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LeadershipDomainService $leadershipService,
    ) {}

    public function current(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $this->authorize('viewAny', LeadershipAssignment::class);

        $data = $this->leadershipService->getCurrentLeadership($tenantId);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $this->authorize('viewAny', LeadershipAssignment::class);

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $paginator = $this->leadershipService->getHistory($tenantId, [
            'person_id' => $request->input('person_id'),
            'role_id' => $request->input('role_id'),
            'category' => $request->input('category'),
            'status' => $request->input('status'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'as_of' => $request->input('as_of'),
        ], $perPage);

        $items = collect($paginator->items())
            ->map(fn (LeadershipAssignment $a) => $this->leadershipService->presentAssignment($a));

        return response()->json([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $this->authorize('viewAny', LeadershipAssignment::class);

        $roles = $this->leadershipService->listRoles($tenantId, $request->input('category'));

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'id' => $role->id,
                'title' => $role->title,
                'category' => $role->category,
                'category_label' => LeadershipRoleCategory::label($role->category),
                'hierarchical_level' => $role->hierarchical_level,
                'allows_concurrent' => $role->allows_concurrent,
                'is_canonical_mandate' => $role->is_canonical_mandate,
                'is_global' => $role->tenant_id === null,
            ])->values(),
        ]);
    }

    public function assign(AssignLeadershipRequest $request): JsonResponse
    {
        $this->authorize('assign', LeadershipAssignment::class);

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $assignment = $this->leadershipService->assignLeader($tenantId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Leadership assignment created successfully.',
                'data' => $this->leadershipService->presentAssignment($assignment),
            ], 201);
        } catch (ChurchLeadershipDomainException $e) {
            return $this->domainError($e);
        }
    }

    public function handover(HandoverLeadershipRequest $request): JsonResponse
    {
        $this->authorize('handover', LeadershipAssignment::class);

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $result = $this->leadershipService->handover($tenantId, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Leadership handover completed successfully.',
                'data' => [
                    'outgoing' => $this->leadershipService->presentAssignment($result['outgoing']),
                    'incoming' => $this->leadershipService->presentAssignment($result['incoming']),
                ],
            ]);
        } catch (ChurchLeadershipDomainException $e) {
            return $this->domainError($e);
        }
    }

    public function update(UpdateLeadershipAssignmentRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $profile = $this->leadershipService->requireChurchProfile($tenantId);

            $assignment = LeadershipAssignment::query()
                ->forTenant($tenantId)
                ->forChurchProfile($profile->id)
                ->whereKey($id)
                ->first();

            if ($assignment === null) {
                throw ChurchLeadershipDomainException::notFound();
            }

            $this->authorize('update', $assignment);

            $updated = $this->leadershipService->updateAssignment(
                $tenantId,
                $id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Leadership assignment updated successfully.',
                'data' => $this->leadershipService->presentAssignment($updated),
            ]);
        } catch (ChurchLeadershipDomainException $e) {
            return $this->domainError($e);
        }
    }

    public function terminate(TerminateLeadershipRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $profile = $this->leadershipService->requireChurchProfile($tenantId);

            $assignment = LeadershipAssignment::query()
                ->forTenant($tenantId)
                ->forChurchProfile($profile->id)
                ->whereKey($id)
                ->first();

            if ($assignment === null) {
                throw ChurchLeadershipDomainException::notFound();
            }

            $this->authorize('terminate', $assignment);

            $terminated = $this->leadershipService->terminateAssignment(
                $tenantId,
                $id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'Leadership assignment terminated successfully.',
                'data' => $this->leadershipService->presentAssignment($terminated),
            ]);
        } catch (ChurchLeadershipDomainException $e) {
            return $this->domainError($e);
        }
    }

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $this->authorize('assign', LeadershipAssignment::class);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ]);

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $file = $validated['image'];
            if (! $file instanceof UploadedFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file upload',
                ], 422);
            }

            $assignment = $this->leadershipService->uploadAssignmentPhoto($tenantId, $id, $file);

            return response()->json([
                'success' => true,
                'message' => 'Leader photo uploaded successfully.',
                'data' => $this->leadershipService->presentAssignment($assignment),
            ]);
        } catch (ChurchLeadershipDomainException $e) {
            return $this->domainError($e);
        }
    }

    private function domainError(ChurchLeadershipDomainException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'errors' => $e->errors,
        ], $e->httpStatus);
    }
}
