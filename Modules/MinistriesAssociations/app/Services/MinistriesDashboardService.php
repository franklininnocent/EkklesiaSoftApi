<?php

namespace Modules\MinistriesAssociations\Services;

use Carbon\Carbon;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\Position;

class MinistriesDashboardService
{
    private const EXPIRING_SOON_DAYS = 30;

    private const TOP_ORGANIZATIONS_LIMIT = 5;

    private const EXPIRING_SOON_LIMIT = 5;

    /**
     * @return array<string, mixed>
     */
    public function summary(int $tenantId): array
    {
        $orgBase = Organization::query()->forTenant($tenantId);
        $totalOrgs = (clone $orgBase)->count();
        $activeOrgs = (clone $orgBase)->where('status', Organization::STATUS_ACTIVE)->count();
        $inactiveOrgs = (clone $orgBase)->where('status', Organization::STATUS_INACTIVE)->count();

        $membershipBase = OrganizationMembership::query()->forTenant($tenantId);
        $activeMembers = (clone $membershipBase)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->count();
        $inactiveMembers = (clone $membershipBase)
            ->where('is_current', true)
            ->where('status', '!=', OrganizationMembership::STATUS_ACTIVE)
            ->count();
        $guestActive = (clone $membershipBase)
            ->where('is_current', true)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->where('member_source', OrganizationMembership::SOURCE_GUEST)
            ->count();

        $filledPositions = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->count();

        $vacancies = $this->sumVacanciesAcrossActiveOrganizations($tenantId);
        $expiringSoon = $this->expiringSoonTerms($tenantId);
        $topOrganizations = $this->topOrganizationsByActiveMembers($tenantId);
        $guestMembersTotal = GuestMember::query()->forTenant($tenantId)->count();

        return [
            'organizations' => [
                'total' => $totalOrgs,
                'active' => $activeOrgs,
                'inactive' => $inactiveOrgs,
            ],
            'memberships' => [
                'active' => $activeMembers,
                'inactive' => $inactiveMembers,
                'guest_active' => $guestActive,
            ],
            'leadership' => [
                'filled_positions' => $filledPositions,
                'vacancies' => $vacancies,
                'expiring_soon' => $expiringSoon,
            ],
            'top_organizations' => $topOrganizations,
            'guest_members_total' => $guestMembersTotal,
        ];
    }

    private function sumVacanciesAcrossActiveOrganizations(int $tenantId): int
    {
        $singleOccupancyPositionIds = Position::query()
            ->forTenant($tenantId)
            ->active()
            ->where('single_occupancy', true)
            ->pluck('id');

        $positionCount = $singleOccupancyPositionIds->count();
        if ($positionCount === 0) {
            return 0;
        }

        $activeOrgIds = Organization::query()
            ->forTenant($tenantId)
            ->where('status', Organization::STATUS_ACTIVE)
            ->pluck('id');

        if ($activeOrgIds->isEmpty()) {
            return 0;
        }

        $filledByOrg = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->whereIn('organization_id', $activeOrgIds)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->whereIn('position_id', $singleOccupancyPositionIds)
            ->selectRaw('organization_id, COUNT(DISTINCT position_id) as filled')
            ->groupBy('organization_id')
            ->pluck('filled', 'organization_id');

        $vacancies = 0;
        foreach ($activeOrgIds as $organizationId) {
            $filled = (int) ($filledByOrg[$organizationId] ?? 0);
            $vacancies += max(0, $positionCount - $filled);
        }

        return $vacancies;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expiringSoonTerms(int $tenantId): array
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays(self::EXPIRING_SOON_DAYS);

        $terms = LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '>=', $today)
            ->whereDate('effective_to', '<=', $horizon)
            ->with([
                'organization:id,name',
                'position:id,name',
                'membership.familyMember:id,first_name,middle_name,last_name',
                'membership.guestMember:id,first_name,last_name',
            ])
            ->orderBy('effective_to')
            ->limit(self::EXPIRING_SOON_LIMIT)
            ->get();

        return $terms->map(function (LeadershipTerm $term): array {
            return [
                'term_id' => $term->id,
                'organization_id' => $term->organization_id,
                'organization_name' => $term->organization?->name,
                'position_name' => $term->position?->name,
                'holder_name' => $this->holderName($term->membership),
                'effective_to' => optional($term->effective_to)?->toDateString(),
                'is_interim' => (bool) $term->is_interim,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topOrganizationsByActiveMembers(int $tenantId): array
    {
        $organizations = Organization::query()
            ->forTenant($tenantId)
            ->where('status', Organization::STATUS_ACTIVE)
            ->withCount([
                'memberships as active_members_count' => function ($query): void {
                    $query->where('is_current', true)
                        ->where('status', OrganizationMembership::STATUS_ACTIVE);
                },
                'leadershipTerms as active_office_bearers_count' => function ($query): void {
                    $query->where('status', LeadershipTerm::STATUS_ACTIVE);
                },
            ])
            ->orderByDesc('active_members_count')
            ->orderBy('name')
            ->limit(self::TOP_ORGANIZATIONS_LIMIT)
            ->get(['id', 'name', 'code']);

        return $organizations->map(static function (Organization $organization): array {
            return [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
                'active_members' => (int) $organization->active_members_count,
                'active_office_bearers' => (int) $organization->active_office_bearers_count,
            ];
        })->all();
    }

    private function holderName(?OrganizationMembership $membership): ?string
    {
        if ($membership === null) {
            return null;
        }

        if ($membership->familyMember) {
            $display = $membership->familyMember->full_name_display;
            $display = is_string($display) ? trim($display) : '';

            return $display !== '' ? $display : null;
        }

        if ($membership->guestMember) {
            $display = trim((string) $membership->guestMember->display_name);

            return $display !== '' ? $display : null;
        }

        return null;
    }
}
