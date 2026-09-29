<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Http\Requests\AssignLeadershipTermRequest;
use Modules\MinistriesAssociations\Http\Requests\IndexLeadershipTimelineRequest;
use Modules\MinistriesAssociations\Http\Requests\LeadershipHandoverRequest;
use Modules\MinistriesAssociations\Http\Requests\TerminateLeadershipTermRequest;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\Position;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;
use Modules\Tenants\Support\TenantContext;

class OrganizationLeadershipController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly MinistriesAuditService $auditService) {}

    public function current(string $organizationId): JsonResponse
    {
        $this->authorize('viewAny', LeadershipTerm::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);

        $positions = Position::query()
            ->forTenant($tenantId)
            ->active()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        $activeTerms = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organization->id)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->with(['membership.familyMember', 'membership.guestMember', 'position'])
            ->get()
            ->groupBy('position_id');

        $positionRows = $positions->map(function (Position $position) use ($activeTerms) {
            $terms = $activeTerms->get($position->id, collect());
            $primaryTerm = $terms->first();

            return [
                'position' => $this->presentPosition($position),
                'current_term' => $primaryTerm ? $this->presentLeadershipTerm($primaryTerm) : null,
                'current_terms' => $terms
                    ->map(fn (LeadershipTerm $term) => $this->presentLeadershipTerm($term))
                    ->values()
                    ->all(),
                'vacant' => $terms->isEmpty(),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'positions' => $positionRows,
            ],
        ]);
    }

    public function timeline(IndexLeadershipTimelineRequest $request, string $organizationId): JsonResponse
    {
        $this->authorize('viewAny', LeadershipTerm::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);
        $validated = $request->validated();

        $query = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organization->id)
            ->with(['membership.familyMember', 'membership.guestMember', 'position'])
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at');

        if (! empty($validated['position_id'])) {
            $query->where('position_id', $validated['position_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())
            ->map(fn (LeadershipTerm $term) => $this->presentLeadershipTerm($term))
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

    public function assign(AssignLeadershipTermRequest $request, string $organizationId): JsonResponse
    {
        $this->authorize('assign', LeadershipTerm::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);
        $payload = $request->validated();

        $blocking = $this->activeOrganizationRequired($organization);
        if ($blocking !== null) {
            return $blocking;
        }

        $membership = $this->resolveActiveMembership($tenantId, $organization->id, $payload['membership_id']);
        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        $position = Position::query()
            ->forTenant($tenantId)
            ->active()
            ->find($payload['position_id']);

        if ($position === null) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found or inactive.',
                'errors' => [
                    'position_id' => ['Position not found or inactive.'],
                ],
            ], 422);
        }

        $guestBlocking = $this->guestOfficeBearerAllowed($organization, $membership);
        if ($guestBlocking !== null) {
            return $guestBlocking;
        }

        $multiRoleBlocking = $this->multiRoleConflict($organization, $membership->id);
        if ($multiRoleBlocking !== null) {
            return $multiRoleBlocking;
        }

        if ($position->single_occupancy) {
            $existingTerm = LeadershipTerm::query()
                ->forTenant($tenantId)
                ->where('organization_id', $organization->id)
                ->where('position_id', $position->id)
                ->where('status', LeadershipTerm::STATUS_ACTIVE)
                ->with(['membership.familyMember', 'membership.guestMember'])
                ->first();

            if ($existingTerm !== null) {
                return $this->leadershipOverlapConflict($existingTerm, $position);
            }

            $overlap = $this->findOverlappingActiveTerm(
                $tenantId,
                $organization->id,
                $position->id,
                $payload['effective_from'],
                $payload['effective_to'] ?? null,
            );

            if ($overlap !== null) {
                return $this->leadershipOverlapConflict($overlap, $position);
            }
        }

        $term = LeadershipTerm::create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'membership_id' => $membership->id,
            'position_id' => $position->id,
            'appointment_date' => $payload['appointment_date'],
            'effective_from' => $payload['effective_from'],
            'effective_to' => $payload['effective_to'] ?? null,
            'term_label' => $payload['term_label'] ?? null,
            'appointment_reference' => $payload['appointment_reference'] ?? null,
            'is_interim' => $payload['is_interim'] ?? false,
            'remarks' => $payload['remarks'] ?? null,
            'status' => LeadershipTerm::STATUS_ACTIVE,
        ]);

        $term->load(['membership.familyMember', 'membership.guestMember', 'position']);

        $this->auditService->log(
            $tenantId,
            'leadership.assigned',
            'leadership_term',
            $term->id,
            null,
            $this->auditSnapshot($term),
            $organization->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'Leadership term assigned successfully.',
            'data' => $this->presentLeadershipTerm($term),
        ], 201);
    }

    public function handover(LeadershipHandoverRequest $request, string $organizationId): JsonResponse
    {
        $this->authorize('handover', LeadershipTerm::class);

        $tenantId = $this->tenantId();
        $organization = $this->findOrganization($tenantId, $organizationId);
        $payload = $request->validated();

        $blocking = $this->activeOrganizationRequired($organization);
        if ($blocking !== null) {
            return $blocking;
        }

        $outgoingTerm = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organization->id)
            ->where('position_id', $payload['position_id'])
            ->find($payload['outgoing_term_id']);

        if ($outgoingTerm === null || ! $outgoingTerm->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Outgoing leadership term not found or not active.',
                'errors' => [
                    'outgoing_term_id' => ['Outgoing term must be active.'],
                ],
            ], 422);
        }

        $position = Position::query()
            ->forTenant($tenantId)
            ->find($payload['position_id']);

        if ($position === null) {
            return response()->json([
                'success' => false,
                'message' => 'Position not found.',
                'errors' => [
                    'position_id' => ['Position not found.'],
                ],
            ], 422);
        }

        $incomingPayload = $payload['incoming'];
        $outgoingPayload = $payload['outgoing'];

        if ($outgoingPayload['effective_to'] < $outgoingTerm->effective_from->toDateString()) {
            return response()->json([
                'success' => false,
                'message' => 'Outgoing effective end date must be on or after the term start date.',
                'errors' => [
                    'outgoing.effective_to' => ['Effective end date is before the term start date.'],
                ],
            ], 422);
        }

        $membership = $this->resolveActiveMembership($tenantId, $organization->id, $incomingPayload['membership_id']);
        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        $guestBlocking = $this->guestOfficeBearerAllowed($organization, $membership);
        if ($guestBlocking !== null) {
            return $guestBlocking;
        }

        $multiRoleBlocking = $this->multiRoleConflict($organization, $membership->id, $outgoingTerm->id);
        if ($multiRoleBlocking !== null) {
            return $multiRoleBlocking;
        }

        $result = DB::transaction(function () use (
            $tenantId,
            $organization,
            $position,
            $outgoingTerm,
            $outgoingPayload,
            $incomingPayload,
            $membership,
        ) {
            $outgoingOld = $this->auditSnapshot($outgoingTerm);

            $outgoingTerm->update([
                'effective_to' => $outgoingPayload['effective_to'],
                'exit_reason' => $outgoingPayload['exit_reason'],
                'status' => $this->statusForHandoverExit($outgoingPayload['exit_reason']),
            ]);
            $outgoingTerm->refresh();

            $incomingTerm = LeadershipTerm::create([
                'tenant_id' => $tenantId,
                'organization_id' => $organization->id,
                'membership_id' => $membership->id,
                'position_id' => $position->id,
                'appointment_date' => $incomingPayload['appointment_date'],
                'effective_from' => $incomingPayload['effective_from'],
                'effective_to' => $incomingPayload['effective_to'],
                'term_label' => $incomingPayload['term_label'] ?? null,
                'appointment_reference' => $incomingPayload['appointment_reference'] ?? null,
                'is_interim' => $incomingPayload['is_interim'] ?? false,
                'remarks' => $incomingPayload['remarks'] ?? null,
                'status' => LeadershipTerm::STATUS_ACTIVE,
            ]);

            $outgoingTerm->load(['membership.familyMember', 'membership.guestMember', 'position']);
            $incomingTerm->load(['membership.familyMember', 'membership.guestMember', 'position']);

            return [
                'outgoing_old' => $outgoingOld,
                'outgoing_term' => $outgoingTerm,
                'incoming_term' => $incomingTerm,
            ];
        });

        $this->auditService->log(
            $tenantId,
            'leadership.handover',
            'leadership_term',
            $result['incoming_term']->id,
            $result['outgoing_old'],
            [
                'outgoing_term' => $this->auditSnapshot($result['outgoing_term']),
                'incoming_term' => $this->auditSnapshot($result['incoming_term']),
            ],
            $organization->id,
            ['outgoing_term_id' => $result['outgoing_term']->id],
        );

        return response()->json([
            'success' => true,
            'message' => 'Leadership handover completed successfully.',
            'data' => [
                'outgoing_term' => $this->presentLeadershipTerm($result['outgoing_term']),
                'incoming_term' => $this->presentLeadershipTerm($result['incoming_term']),
            ],
        ], 201);
    }

    public function terminate(
        TerminateLeadershipTermRequest $request,
        string $organizationId,
        string $termId,
    ): JsonResponse {
        $tenantId = $this->tenantId();
        $term = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->findOrFail($termId);

        $this->authorize('terminate', $term);

        if (! $term->isActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Only active leadership terms can be terminated.',
                'errors' => [
                    'term' => ['Leadership term is not active.'],
                ],
            ], 422);
        }

        $payload = $request->validated();

        if ($payload['effective_to'] < $term->effective_from->toDateString()) {
            return response()->json([
                'success' => false,
                'message' => 'Effective end date must be on or after the term start date.',
                'errors' => [
                    'effective_to' => ['Effective end date is before the term start date.'],
                ],
            ], 422);
        }

        $oldValues = $this->auditSnapshot($term);
        $term->update([
            'effective_to' => $payload['effective_to'],
            'exit_reason' => $payload['exit_reason'],
            'remarks' => $payload['remarks'] ?? $term->remarks,
            'status' => LeadershipTerm::STATUS_TERMINATED,
        ]);
        $term->refresh()->load(['membership.familyMember', 'membership.guestMember', 'position']);

        $this->auditService->log(
            $tenantId,
            'leadership.terminated',
            'leadership_term',
            $term->id,
            $oldValues,
            $this->auditSnapshot($term),
            $organizationId,
        );

        return response()->json([
            'success' => true,
            'message' => 'Leadership term terminated successfully.',
            'data' => $this->presentLeadershipTerm($term),
        ]);
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireEffectiveTenantId();
    }

    private function findOrganization(int $tenantId, string $organizationId): Organization
    {
        return Organization::query()
            ->forTenant($tenantId)
            ->findOrFail($organizationId);
    }

    private function activeOrganizationRequired(Organization $organization): ?JsonResponse
    {
        if ($organization->status === Organization::STATUS_ACTIVE) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Cannot assign leadership in an inactive organization.',
            'errors' => [
                'organization' => ['Organization must be active.'],
            ],
        ], 422);
    }

    private function resolveActiveMembership(
        int $tenantId,
        string $organizationId,
        string $membershipId,
    ): OrganizationMembership|JsonResponse {
        $membership = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->where('id', $membershipId)
            ->with(['familyMember', 'guestMember'])
            ->first();

        if ($membership === null) {
            return response()->json([
                'success' => false,
                'message' => 'Membership not found in this organization.',
                'errors' => [
                    'membership_id' => ['Membership not found in this organization.'],
                ],
            ], 422);
        }

        if (! $membership->is_current || $membership->status !== OrganizationMembership::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Membership must be active in this organization.',
                'errors' => [
                    'membership_id' => ['Membership must be active in this organization.'],
                ],
            ], 422);
        }

        return $membership;
    }

    private function guestOfficeBearerAllowed(Organization $organization, OrganizationMembership $membership): ?JsonResponse
    {
        if (! $membership->isGuestMember() || $organization->guests_can_hold_office) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Guest members cannot hold office in this organization.',
            'errors' => [
                'membership_id' => ['Guest office bearers are not allowed for this organization.'],
            ],
        ], 422);
    }

    private function multiRoleConflict(
        Organization $organization,
        string $membershipId,
        ?string $excludeTermId = null,
    ): ?JsonResponse {
        if ($organization->allow_multi_role_holding) {
            return null;
        }

        $query = LeadershipTerm::query()
            ->forTenant((int) $organization->tenant_id)
            ->where('organization_id', $organization->id)
            ->where('membership_id', $membershipId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE);

        if ($excludeTermId !== null) {
            $query->where('id', '!=', $excludeTermId);
        }

        if (! $query->exists()) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Member already holds an active leadership role in this organization.',
            'errors' => [
                'membership_id' => ['Multi-role holding is disabled for this organization.'],
            ],
        ], 422);
    }

    private function findOverlappingActiveTerm(
        int $tenantId,
        string $organizationId,
        string $positionId,
        string $effectiveFrom,
        ?string $effectiveTo,
        ?string $excludeTermId = null,
    ): ?LeadershipTerm {
        $query = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organizationId)
            ->where('position_id', $positionId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->where(function ($dateQuery) use ($effectiveFrom, $effectiveTo): void {
                $dateQuery->where(function ($incomingEndQuery) use ($effectiveFrom, $effectiveTo): void {
                    if ($effectiveTo !== null) {
                        $incomingEndQuery
                            ->where('effective_from', '<=', $effectiveTo)
                            ->where(function ($existingEndQuery) use ($effectiveFrom): void {
                                $existingEndQuery
                                    ->whereNull('effective_to')
                                    ->orWhere('effective_to', '>=', $effectiveFrom);
                            });
                    } else {
                        $incomingEndQuery->where(function ($existingEndQuery) use ($effectiveFrom): void {
                            $existingEndQuery
                                ->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', $effectiveFrom);
                        });
                    }
                });
            })
            ->with(['membership.familyMember', 'membership.guestMember']);

        if ($excludeTermId !== null) {
            $query->where('id', '!=', $excludeTermId);
        }

        return $query->first();
    }

    private function leadershipOverlapConflict(LeadershipTerm $existingTerm, Position $position): JsonResponse
    {
        $holder = $this->presentHolder($existingTerm->membership);

        return response()->json([
            'success' => false,
            'message' => 'Cannot assign '.$position->name.' while an active term exists.',
            'errors' => [
                'conflict' => [
                    'type' => 'leadership_overlap',
                    'existing_term_id' => $existingTerm->id,
                    'existing_holder' => [
                        'id' => $existingTerm->membership_id,
                        'display_name' => $holder['display_name'] ?? null,
                    ],
                ],
            ],
        ], 409);
    }

    private function statusForHandoverExit(string $exitReason): string
    {
        return $exitReason === LeadershipTerm::EXIT_REASON_TERM_COMPLETED
            ? LeadershipTerm::STATUS_COMPLETED
            : LeadershipTerm::STATUS_VACATED;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPosition(Position $position): array
    {
        return [
            'id' => $position->id,
            'name' => $position->name,
            'code' => $position->code,
            'single_occupancy' => $position->single_occupancy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLeadershipTerm(LeadershipTerm $term): array
    {
        $term->loadMissing(['membership.familyMember', 'membership.guestMember', 'position']);

        return [
            'id' => $term->id,
            'organization_id' => $term->organization_id,
            'membership_id' => $term->membership_id,
            'position_id' => $term->position_id,
            'position' => $term->position ? $this->presentPosition($term->position) : null,
            'holder' => $this->presentHolder($term->membership),
            'appointment_date' => $term->appointment_date?->toDateString(),
            'effective_from' => $term->effective_from?->toDateString(),
            'effective_to' => $term->effective_to?->toDateString(),
            'term_label' => $term->term_label,
            'appointment_reference' => $term->appointment_reference,
            'is_interim' => $term->is_interim,
            'status' => $term->status,
            'exit_reason' => $term->exit_reason,
            'remarks' => $term->remarks,
            'created_at' => $term->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentHolder(?OrganizationMembership $membership): ?array
    {
        if ($membership === null) {
            return null;
        }

        $membership->loadMissing(['familyMember', 'guestMember']);
        $displayName = null;

        if ($membership->isParishMember() && $membership->familyMember) {
            $displayName = $membership->familyMember->full_name_display;
        } elseif ($membership->isGuestMember() && $membership->guestMember) {
            $displayName = $membership->guestMember->display_name;
        }

        return [
            'display_name' => $displayName,
            'member_source' => $membership->member_source,
            'family_member_id' => $membership->family_member_id,
            'guest_member_id' => $membership->guest_member_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(LeadershipTerm $term): array
    {
        return [
            'id' => $term->id,
            'organization_id' => $term->organization_id,
            'membership_id' => $term->membership_id,
            'position_id' => $term->position_id,
            'status' => $term->status,
            'effective_from' => $term->effective_from?->toDateString(),
            'effective_to' => $term->effective_to?->toDateString(),
            'exit_reason' => $term->exit_reason,
            'is_interim' => $term->is_interim,
        ];
    }
}
