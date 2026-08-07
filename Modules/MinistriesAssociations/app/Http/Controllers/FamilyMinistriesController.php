<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Family\Models\FamilyMember;
use Modules\MinistriesAssociations\Http\Requests\EnrollFamilyMemberRequest;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;

class FamilyMinistriesController extends Controller
{
    use AuthorizesRequests;

    /** @var list<string> */
    private const INELIGIBLE_FAMILY_STATUSES = ['deceased', 'migrated'];

    public function __construct(private readonly MinistriesAuditService $auditService)
    {
    }

    public function affiliations(string $familyMemberId): JsonResponse
    {
        $this->authorize('ministries.viewFamilyAffiliations');

        $tenantId = $this->tenantId();
        $familyMember = $this->findFamilyMember($tenantId, $familyMemberId);

        $memberships = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('family_member_id', $familyMember->id)
            ->where('member_source', OrganizationMembership::SOURCE_PARISH)
            ->with('organization')
            ->orderBy('joined_date')
            ->get();

        $membershipIds = $memberships->pluck('id')->all();

        $leadershipTerms = $membershipIds === []
            ? collect()
            : LeadershipTerm::query()
                ->forTenant($tenantId)
                ->whereIn('membership_id', $membershipIds)
                ->with('position')
                ->orderByDesc('effective_from')
                ->get()
                ->groupBy('organization_id');

        $affiliations = $memberships
            ->groupBy('organization_id')
            ->map(function ($organizationMemberships, string $organizationId) use ($leadershipTerms) {
                /** @var OrganizationMembership $firstMembership */
                $firstMembership = $organizationMemberships->first();
                $organization = $firstMembership->organization;

                return [
                    'organization' => [
                        'id' => $organization?->id,
                        'name' => $organization?->name,
                        'code' => $organization?->code,
                        'status' => $organization?->status,
                    ],
                    'memberships' => $organizationMemberships
                        ->map(fn (OrganizationMembership $membership) => [
                            'membership_id' => $membership->id,
                            'status' => $membership->status,
                            'joined_date' => $membership->joined_date?->toDateString(),
                            'exit_date' => $membership->exit_date?->toDateString(),
                            'interval_label' => $this->intervalLabel($membership),
                        ])
                        ->values()
                        ->all(),
                    'leadership_terms' => ($leadershipTerms->get($organizationId) ?? collect())
                        ->map(fn (LeadershipTerm $term) => [
                            'term_id' => $term->id,
                            'position_name' => $term->position?->name,
                            'is_interim' => $term->is_interim,
                            'effective_from' => $term->effective_from?->toDateString(),
                            'effective_to' => $term->effective_to?->toDateString(),
                            'status' => $term->status,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'family_member_id' => $familyMember->id,
                'affiliations' => $affiliations,
            ],
        ]);
    }

    public function enroll(EnrollFamilyMemberRequest $request, string $familyMemberId): JsonResponse
    {
        $this->authorize('ministries.enrollFamilyMember');

        $tenantId = $this->tenantId();
        $familyMember = $this->findFamilyMember($tenantId, $familyMemberId);
        $payload = $request->validated();

        $organization = Organization::query()
            ->forTenant($tenantId)
            ->find($payload['organization_id']);

        if ($organization === null) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found.',
                'errors' => [
                    'organization_id' => ['Organization not found.'],
                ],
            ], 422);
        }

        if ($organization->status !== Organization::STATUS_ACTIVE) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot enroll members into an inactive organization.',
                'errors' => [
                    'organization_id' => ['Organization must be active.'],
                ],
            ], 422);
        }

        $eligibility = $this->parishMemberEligibility($familyMember);
        if ($eligibility !== null) {
            return $eligibility;
        }

        $duplicate = OrganizationMembership::query()
            ->forTenant($tenantId)
            ->where('organization_id', $organization->id)
            ->where('member_source', OrganizationMembership::SOURCE_PARISH)
            ->where('family_member_id', $familyMember->id)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'An active enrollment already exists for this person in this organization.',
                'errors' => [
                    'member' => ['Duplicate active enrollment.'],
                ],
            ], 422);
        }

        $membership = OrganizationMembership::create([
            'tenant_id' => $tenantId,
            'organization_id' => $organization->id,
            'member_source' => OrganizationMembership::SOURCE_PARISH,
            'family_member_id' => $familyMember->id,
            'guest_member_id' => null,
            'member_type' => $payload['member_type'] ?? 'regular',
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_date' => $payload['joined_date'],
            'remarks' => $payload['remarks'] ?? null,
            'is_current' => true,
        ]);

        $membership->load(['familyMember.family', 'organization']);

        $this->auditService->log(
            $tenantId,
            'membership.enrolled',
            'membership',
            $membership->id,
            null,
            $this->auditSnapshot($membership),
            $organization->id,
            ['enrollment_source' => 'family_profile'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Member enrolled successfully.',
            'data' => $this->presentMembership($membership),
        ], 201);
    }

    private function tenantId(): int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
    }

    private function findFamilyMember(int $tenantId, string $familyMemberId): FamilyMember
    {
        return FamilyMember::query()
            ->whereHas('family', fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))
            ->findOrFail($familyMemberId);
    }

    private function parishMemberEligibility(FamilyMember $familyMember): ?JsonResponse
    {
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
    private function presentMembership(OrganizationMembership $membership): array
    {
        $membership->loadMissing(['familyMember.family', 'organization']);

        return [
            'id' => $membership->id,
            'organization_id' => $membership->organization_id,
            'member_source' => $membership->member_source,
            'family_member_id' => $membership->family_member_id,
            'guest_member_id' => $membership->guest_member_id,
            'display_name' => $membership->familyMember?->full_name_display,
            'family_name' => $membership->familyMember?->family?->family_name,
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
            'member_type' => $membership->member_type,
            'status' => $membership->status,
            'joined_date' => $membership->joined_date?->toDateString(),
            'is_current' => $membership->is_current,
        ];
    }
}
