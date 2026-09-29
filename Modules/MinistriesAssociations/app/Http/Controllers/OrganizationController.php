<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\MinistriesAssociations\Http\Requests\IndexOrganizationRequest;
use Modules\MinistriesAssociations\Http\Requests\StoreOrganizationRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateOrganizationRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateOrganizationStatusRequest;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\Position;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class OrganizationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function index(IndexOrganizationRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        $tenantId = $this->tenantId();
        $validated = $request->validated();

        $includes = $this->parseIncludes($validated['include'] ?? null);

        $query = Organization::query()
            ->forTenant($tenantId)
            ->withCount([
                'memberships as active_member_count' => function ($membershipQuery): void {
                    $membershipQuery
                        ->where('is_current', true)
                        ->where('status', OrganizationMembership::STATUS_ACTIVE);
                },
            ]);

        if (in_array('counts', $includes, true)) {
            $query->withCount([
                'memberships as total_members_count',
                'memberships as active_members_count' => function ($membershipQuery): void {
                    $membershipQuery
                        ->where('is_current', true)
                        ->where('status', OrganizationMembership::STATUS_ACTIVE);
                },
                'leadershipTerms as active_office_bearers_count' => function ($leadershipQuery): void {
                    $leadershipQuery->where('status', LeadershipTerm::STATUS_ACTIVE);
                },
            ]);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->where('name', 'ilike', $search)
                    ->orWhere('code', 'ilike', $search)
                    ->orWhere('short_name', 'ilike', $search);
            });
        }

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (! empty($validated['type_id'])) {
            $query->where('type_id', $validated['type_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $this->applyIncludes($query, $includes);

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (Organization $organization) => $this->presentOrganization($organization, $includes))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', Organization::class);

        $tenantId = $this->tenantId();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['status'] = $payload['status'] ?? Organization::STATUS_ACTIVE;

        $organization = Organization::create($payload);

        $this->auditService->log(
            $tenantId,
            'organization.created',
            'organization',
            $organization->id,
            null,
            $this->auditSnapshot($organization),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization created successfully.',
            'data' => $this->presentOrganization($organization->fresh(['category', 'type']), ['category', 'type', 'settings']),
        ], 201);
    }

    public function show(Request $request, string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $includes = $this->parseIncludes($request->query('include'));
        if ($includes === []) {
            $includes = ['category', 'type', 'settings'];
        }

        $query = Organization::query()->forTenant($tenantId);
        $this->applyIncludes($query, $includes);

        $organization = $query->findOrFail($organizationId);

        $this->authorize('view', $organization);

        return response()->json([
            'success' => true,
            'data' => $this->presentOrganization($organization, $includes),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);

        $this->authorize('update', $organization);

        $oldValues = $this->auditSnapshot($organization);
        $organization->update($request->validated());
        $organization->refresh();

        $this->auditService->log(
            $tenantId,
            'organization.updated',
            'organization',
            $organization->id,
            $oldValues,
            $this->auditSnapshot($organization),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization updated successfully.',
            'data' => $this->presentOrganization($organization->fresh(['category', 'type']), ['category', 'type', 'settings']),
        ]);
    }

    public function updateStatus(UpdateOrganizationStatusRequest $request, string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);

        $this->authorize('updateStatus', $organization);

        $oldValues = $this->auditSnapshot($organization);
        $organization->update($request->validated());
        $organization->refresh();

        $this->auditService->log(
            $tenantId,
            'organization.status_changed',
            'organization',
            $organization->id,
            $oldValues,
            $this->auditSnapshot($organization),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization status updated successfully.',
            'data' => $this->presentOrganization($organization, ['settings']),
        ]);
    }

    public function destroy(string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);

        $this->authorize('delete', $organization);

        $hasActiveMemberships = $organization->memberships()
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists();

        if ($hasActiveMemberships) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete organization while active memberships exist.',
                'errors' => [
                    'conflict' => [
                        'type' => 'active_memberships',
                    ],
                ],
            ], 409);
        }

        $hasActiveLeadership = $organization->leadershipTerms()
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->exists();

        if ($hasActiveLeadership) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete organization while active leadership terms exist.',
                'errors' => [
                    'conflict' => [
                        'type' => 'active_leadership',
                    ],
                ],
            ], 409);
        }

        $oldValues = $this->auditSnapshot($organization);
        $organization->delete();

        $this->auditService->log(
            $tenantId,
            'organization.deleted',
            'organization',
            $organization->id,
            $oldValues,
            ['deleted_at' => $organization->deleted_at?->toIso8601String()],
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization archived successfully.',
            'data' => [
                'id' => $organization->id,
                'deleted_at' => $organization->deleted_at?->toIso8601String(),
            ],
        ]);
    }

    public function restore(string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->onlyTrashed()
            ->findOrFail($organizationId);

        $this->authorize('restore', $organization);

        $organization->restore();
        $organization->refresh();

        $this->auditService->log(
            $tenantId,
            'organization.restored',
            'organization',
            $organization->id,
            ['deleted_at' => 'trashed'],
            $this->auditSnapshot($organization),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Organization restored successfully.',
            'data' => $this->presentOrganization($organization->fresh(['category', 'type']), ['category', 'type', 'settings']),
        ]);
    }

    public function summary(string $organizationId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $organization = Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);

        $this->authorize('view', $organization);

        $membershipBase = $organization->memberships();
        $totalMembers = (clone $membershipBase)->count();
        $activeMembers = (clone $membershipBase)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->count();
        $guestMembers = (clone $membershipBase)
            ->where('member_source', OrganizationMembership::SOURCE_GUEST)
            ->count();

        $activeOfficeBearers = $organization->leadershipTerms()
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->count();

        $singleOccupancyPositionIds = Position::query()
            ->forTenant($tenantId)
            ->active()
            ->where('single_occupancy', true)
            ->pluck('id');

        $filledSingleOccupancyPositions = $singleOccupancyPositionIds->isEmpty()
            ? 0
            : $organization->leadershipTerms()
                ->where('status', LeadershipTerm::STATUS_ACTIVE)
                ->whereIn('position_id', $singleOccupancyPositionIds)
                ->distinct()
                ->count('position_id');

        return response()->json([
            'success' => true,
            'data' => [
                'total_members' => $totalMembers,
                'active_members' => $activeMembers,
                'guest_members' => $guestMembers,
                'active_office_bearers' => $activeOfficeBearers,
                'leadership_vacancies' => max(0, $singleOccupancyPositionIds->count() - $filledSingleOccupancyPositions),
            ],
        ]);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    /**
     * @return list<string>
     */
    private function parseIncludes(?string $include): array
    {
        if ($include === null || $include === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $include))));
    }

    /**
     * @param  list<string>  $includes
     */
    private function applyIncludes($query, array $includes): void
    {
        $relations = [];

        if (in_array('category', $includes, true)) {
            $relations[] = 'category';
        }

        if (in_array('type', $includes, true)) {
            $relations[] = 'type';
        }

        if ($relations !== []) {
            $query->with($relations);
        }
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    private function presentOrganization(Organization $organization, array $includes = []): array
    {
        $data = $organization->toArray();
        unset($data['allow_multi_role_holding'], $data['guests_can_hold_office']);

        if (in_array('settings', $includes, true)) {
            $data['settings'] = [
                'allow_multi_role_holding' => (bool) $organization->allow_multi_role_holding,
                'guests_can_hold_office' => (bool) $organization->guests_can_hold_office,
            ];
        }

        if (in_array('counts', $includes, true)) {
            $data['counts'] = $this->organizationCounts($organization);
        }

        return $data;
    }

    /**
     * @return array<string, int>
     */
    private function organizationCounts(Organization $organization): array
    {
        if (isset($organization->total_members_count)) {
            return [
                'total_members' => (int) $organization->total_members_count,
                'active_members' => (int) $organization->active_members_count,
                'active_office_bearers' => (int) $organization->active_office_bearers_count,
            ];
        }

        $activeMembers = $organization->memberships()
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->count();

        $totalMembers = $organization->memberships()->count();
        $activeOfficeBearers = $organization->leadershipTerms()
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->count();

        return [
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'active_office_bearers' => $activeOfficeBearers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'code' => $organization->code,
            'name' => $organization->name,
            'status' => $organization->status,
            'category_id' => $organization->category_id,
            'type_id' => $organization->type_id,
        ];
    }
}
