<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Http\Requests\IndexOrganizationMembershipRequest;
use Modules\MinistriesAssociations\Http\Requests\ReEnrollOrganizationMembershipRequest;
use Modules\MinistriesAssociations\Http\Requests\StoreOrganizationMembershipRequest;
use Modules\MinistriesAssociations\Http\Requests\UpdateOrganizationMembershipStatusRequest;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class OrganizationMembershipController extends Controller
{
    use AuthorizesRequests;

    /** @var list<string> */
    private const EXIT_STATUSES = [
        OrganizationMembership::STATUS_EXITED,
        OrganizationMembership::STATUS_RESIGNED,
        OrganizationMembership::STATUS_INACTIVE,
        OrganizationMembership::STATUS_DECEASED,
    ];

    /** @var list<string> */
    private const INELIGIBLE_FAMILY_STATUSES = ['deceased', 'migrated'];

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function index(IndexOrganizationMembershipRequest $request, string $organizationId): JsonResponse
    {
        $this->authorize('viewAny', OrganizationMembership::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);
        $validated = $request->validated();

        $query = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organization->id)
            ->with(['familyMember.family', 'guestMember'])
            ->orderByDesc('joined_date')
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['member_type'])) {
            $query->where('member_type', $validated['member_type']);
        }

        if (! empty($validated['member_source'])) {
            $query->where('member_source', $validated['member_source']);
        }

        if (array_key_exists('is_current', $validated)) {
            $query->where('is_current', (bool) $validated['is_current']);
        }

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->whereHas('familyMember', function ($familyMemberQuery) use ($search): void {
                        $familyMemberQuery->where(function ($nameQuery) use ($search): void {
                            $nameQuery
                                ->where('first_name', 'ilike', $search)
                                ->orWhere('middle_name', 'ilike', $search)
                                ->orWhere('last_name', 'ilike', $search);
                        });
                    })
                    ->orWhereHas('familyMember.family', function ($familyQuery) use ($search): void {
                        $familyQuery->where('family_name', 'ilike', $search);
                    })
                    ->orWhereHas('guestMember', function ($guestQuery) use ($search): void {
                        $guestQuery->where(function ($nameQuery) use ($search): void {
                            $nameQuery
                                ->where('first_name', 'ilike', $search)
                                ->orWhere('last_name', 'ilike', $search);
                        });
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (OrganizationMembership $membership) => $this->presentMembership($membership))
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

    public function store(StoreOrganizationMembershipRequest $request, string $organizationId): JsonResponse
    {
        $this->authorize('create', OrganizationMembership::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);

        $blocking = $this->activeOrganizationRequired($organization);
        if ($blocking !== null) {
            return $blocking;
        }

        $payload = $request->validated();

        if ($payload['member_source'] === OrganizationMembership::SOURCE_PARISH) {
            $eligibility = $this->parishMemberEligibility($tenantId, $payload['family_member_id']);
            if ($eligibility !== null) {
                return $eligibility;
            }
        }

        $duplicate = $this->duplicateActiveEnrollment(
            $tenantId,
            $organization->id,
            $payload['member_source'],
            $payload['family_member_id'] ?? null,
            $payload['guest_member_id'] ?? null,
        );
        if ($duplicate !== null) {
            return $duplicate;
        }

        $membership = OrganizationMembership::create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => $payload['member_source'],
            'family_member_id' => $payload['family_member_id'] ?? null,
            'guest_member_id' => $payload['guest_member_id'] ?? null,
            'member_type' => $payload['member_type'] ?? 'regular',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => $payload['joined_date'],
            'remarks' => $payload['remarks'] ?? null,
            'emergency_contact' => $payload['emergency_contact'] ?? null,
            'is_current' => true,
        ]);

        $membership->load(['familyMember.family', 'guestMember']);

        $this->auditService->log(
            $tenantId,
            'membership.enrolled',
            'membership',
            $membership->id,
            null,
            $this->auditSnapshot($membership),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Member enrolled successfully.',
            'data' => $this->presentMembership($membership),
        ], 201);
    }

    public function show(Request $request, string $organizationId, string $membershipId): JsonResponse
    {
        $tenantId = $this->tenantId();
        $membership = $this->findMembership($tenantId, $organizationId, $membershipId);

        $this->authorize('view', $membership);

        $membership->load(['familyMember.family', 'guestMember']);
        $includes = $this->parseIncludes($request->query('include'));
        $data = $this->presentMembership($membership);

        if (in_array('history', $includes, true)) {
            $data['history'] = $this->membershipHistory($tenantId, $organizationId, $membership)
                ->map(fn (OrganizationMembership $interval) => $this->presentMembership($interval))
                ->values()
                ->all();
        }

        if (in_array('leadership', $includes, true)) {
            $membership->load('leadershipTerms.position');
            $data['leadership'] = $membership->leadershipTerms
                ->map(fn ($term) => [
                    'id' => $term->id,
                    'position_id' => $term->position_id,
                    'position' => $term->position ? [
                        'id' => $term->position->id,
                        'code' => $term->position->code,
                        'name' => $term->position->name,
                    ] : null,
                    'status' => $term->status,
                    'effective_from' => $term->effective_from?->toDateString(),
                    'effective_to' => $term->effective_to?->toDateString(),
                    'is_interim' => $term->is_interim,
                ])
                ->values()
                ->all();
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function updateStatus(
        UpdateOrganizationMembershipStatusRequest $request,
        string $organizationId,
        string $membershipId,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $membership = $this->findMembership($tenantId, $organizationId, $membershipId);

        $this->authorize('updateStatus', $membership);

        if (! $membership->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'Closed membership intervals cannot be modified.',
                'errors' => [
                    'membership' => ['This enrollment interval is closed.'],
                ],
            ], 422);
        }

        $payload = $request->validated();
        $status = $payload['status'];
        $exitDate = $payload['exit_date'] ?? null;

        if ($exitDate !== null && $exitDate < $membership->joined_date->toDateString()) {
            return response()->json([
                'success' => false,
                'message' => 'Exit date must be on or after the joined date.',
                'errors' => [
                    'exit_date' => ['Exit date must be on or after the joined date.'],
                ],
            ], 422);
        }

        $oldValues = $this->auditSnapshot($membership);
        $updates = [
            'status' => $status,
            'exit_reason' => $payload['exit_reason'] ?? null,
        ];

        if (in_array($status, self::EXIT_STATUSES, true)) {
            $updates['exit_date'] = $exitDate;
            $updates['is_current'] = false;
        }

        $membership->update($updates);
        $membership->refresh()->load(['familyMember.family', 'guestMember']);

        $this->auditService->log(
            $tenantId,
            'membership.status_changed',
            'membership',
            $membership->id,
            $oldValues,
            $this->auditSnapshot($membership),
            $organizationId,
        );

        return response()->json([
            'success' => true,
            'message' => 'Membership status updated successfully.',
            'data' => $this->presentMembership($membership),
        ]);
    }

    public function reEnroll(
        ReEnrollOrganizationMembershipRequest $request,
        string $organizationId,
        string $membershipId,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);
        $priorMembership = $this->findMembership($tenantId, $organizationId, $membershipId);

        $this->authorize('reEnroll', $priorMembership);

        $blocking = $this->activeOrganizationRequired($organization);
        if ($blocking !== null) {
            return $blocking;
        }

        if ($priorMembership->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'Only closed membership intervals can be re-enrolled.',
                'errors' => [
                    'membership' => ['Close the current interval before re-enrolling.'],
                ],
            ], 422);
        }

        if ($priorMembership->member_source === OrganizationMembership::SOURCE_PARISH) {
            $eligibility = $this->parishMemberEligibility($tenantId, $priorMembership->family_member_id);
            if ($eligibility !== null) {
                return $eligibility;
            }
        }

        $payload = $request->validated();
        $joinedDate = $payload['joined_date'];

        if ($priorMembership->exit_date !== null && $joinedDate <= $priorMembership->exit_date->toDateString()) {
            return response()->json([
                'success' => false,
                'message' => 'Re-enrollment date must be after the prior exit date.',
                'errors' => [
                    'joined_date' => ['Joined date must be after the prior exit date.'],
                ],
            ], 422);
        }

        $duplicate = $this->duplicateActiveEnrollment(
            $tenantId,
            $organization->id,
            $priorMembership->member_source,
            $priorMembership->family_member_id,
            $priorMembership->guest_member_id,
        );
        if ($duplicate !== null) {
            return $duplicate;
        }

        $membership = OrganizationMembership::create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => $priorMembership->member_source,
            'family_member_id' => $priorMembership->family_member_id,
            'guest_member_id' => $priorMembership->guest_member_id,
            'member_type' => $payload['member_type'] ?? $priorMembership->member_type,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => $joinedDate,
            'remarks' => $payload['remarks'] ?? null,
            'emergency_contact' => $priorMembership->emergency_contact,
            'is_current' => true,
        ]);

        $membership->load(['familyMember.family', 'guestMember']);

        $this->auditService->log(
            $tenantId,
            'membership.re_enrolled',
            'membership',
            $membership->id,
            $this->auditSnapshot($priorMembership),
            $this->auditSnapshot($membership),
            $organization->id,
            ['prior_membership_id' => $priorMembership->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Member re-enrolled successfully.',
            'data' => $this->presentMembership($membership),
        ], 201);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findOrganization(int $tenantId, string $organizationId): Organization
    {
        return Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);
    }

    private function findMembership(int $tenantId, string $organizationId, string $membershipId): OrganizationMembership
    {
        return OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->findOrFail($membershipId);
    }

    private function activeOrganizationRequired(Organization $organization): ?JsonResponse
    {
        if ($organization->status === Organization::STATUS_ACTIVE) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Cannot enroll members into an inactive organization.',
            'errors' => [
                'organization' => ['Organization must be active.'],
            ],
        ], 422);
    }

    private function parishMemberEligibility(int $tenantId, ?string $familyMemberId): ?JsonResponse
    {
        if ($familyMemberId === null) {
            return response()->json([
                'success' => false,
                'message' => 'Parish member is required.',
                'errors' => [
                    'family_member_id' => ['Parish member is required.'],
                ],
            ], 422);
        }

        $familyMember = FamilyMember::query()
            ->whereHas('family', fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))
            ->find($familyMemberId);

        if ($familyMember === null) {
            return response()->json([
                'success' => false,
                'message' => 'Parish member not found.',
                'errors' => [
                    'family_member_id' => ['Parish member not found.'],
                ],
            ], 422);
        }

        if (in_array($familyMember->status, self::INELIGIBLE_FAMILY_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Parish member is not eligible for enrollment.',
                'errors' => [
                    'family_member_id' => ['Member is deceased or transferred in parish records.'],
                ],
            ], 422);
        }

        return null;
    }

    private function duplicateActiveEnrollment(
        int $tenantId,
        string $organizationId,
        string $memberSource,
        ?string $familyMemberId,
        ?string $guestMemberId,
    ): ?JsonResponse {
        $query = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->where('member_source', $memberSource);

        if ($memberSource === OrganizationMembership::SOURCE_PARISH) {
            $query->where('family_member_id', $familyMemberId);
        } else {
            $query->where('guest_member_id', $guestMemberId);
        }

        if (! $query->exists()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'An active enrollment already exists for this person in this organization.',
            'errors' => [
                'member' => ['Duplicate active enrollment.'],
            ],
        ], 422);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, OrganizationMembership>
     */
    private function membershipHistory(int $tenantId, string $organizationId, OrganizationMembership $membership)
    {
        $query = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->with(['familyMember.family', 'guestMember'])
            ->orderBy('joined_date');

        if ($membership->member_source === OrganizationMembership::SOURCE_PARISH) {
            $query->where('family_member_id', $membership->family_member_id);
        } else {
            $query->where('guest_member_id', $membership->guest_member_id);
        }

        return $query->get();
    }

    /**
     * @return list<string>
     */
    private function parseIncludes(mixed $include): array
    {
        if (! is_string($include) || trim($include) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $include))));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMembership(OrganizationMembership $membership): array
    {
        $displayName = null;
        $familyName = null;

        if ($membership->member_source === OrganizationMembership::SOURCE_PARISH && $membership->familyMember) {
            $displayName = $membership->familyMember->full_name_display;
            $familyName = $membership->familyMember->family?->family_name;
        } elseif ($membership->member_source === OrganizationMembership::SOURCE_GUEST && $membership->guestMember) {
            $displayName = $membership->guestMember->display_name;
        }

        return [
            'id' => $membership->id,
            'organization_id' => $membership->organization_id,
            'member_source' => $membership->member_source,
            'family_member_id' => $membership->family_member_id,
            'guest_member_id' => $membership->guest_member_id,
            'display_name' => $displayName,
            'family_name' => $familyName,
            'member_type' => $membership->member_type,
            'status' => $membership->status,
            'joined_date' => $membership->joined_date?->toDateString(),
            'exit_date' => $membership->exit_date?->toDateString(),
            'exit_reason' => $membership->exit_reason,
            'remarks' => $membership->remarks,
            'emergency_contact' => $membership->emergency_contact,
            'is_current' => $membership->is_current,
            'interval_label' => $this->intervalLabel($membership),
            'created_at' => $membership->created_at?->toIso8601String(),
        ];
    }

    private function intervalLabel(OrganizationMembership $membership): string
    {
        $startYear = $membership->joined_date?->format('Y') ?? '';

        if ($membership->exit_date === null) {
            return $startYear !== '' ? $startYear.'–Present' : 'Present';
        }

        return $startYear.'–'.$membership->exit_date->format('Y');
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(OrganizationMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'organization_id' => $membership->organization_id,
            'member_source' => $membership->member_source,
            'family_member_id' => $membership->family_member_id,
            'guest_member_id' => $membership->guest_member_id,
            'member_type' => $membership->member_type,
            'status' => $membership->status,
            'joined_date' => $membership->joined_date?->toDateString(),
            'exit_date' => $membership->exit_date?->toDateString(),
            'is_current' => $membership->is_current,
        ];
    }
}
