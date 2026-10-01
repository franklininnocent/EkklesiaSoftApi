<?php

namespace Modules\MinistriesAssociations\Services;

use Carbon\Carbon;
use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
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
        $expiringSoonCount = $this->countExpiringSoonTerms($tenantId);
        $topOrganizations = $this->topOrganizationsByActiveMembers($tenantId);
        $guestMembersTotal = GuestMember::query()->forTenant($tenantId)->count();
        $activeByCategory = $this->countActiveOrganizationsByCategory($tenantId);
        $activeByType = $this->countActiveOrganizationsByType($tenantId);
        $categoryBreakdown = $this->countActiveOrganizationsByCategoryBreakdown($tenantId);

        return [
            'organizations' => [
                'total' => $totalOrgs,
                'active' => $activeOrgs,
                'inactive' => $inactiveOrgs,
                'active_by_category' => $activeByCategory,
                'active_by_type' => $activeByType,
                'category_breakdown' => $categoryBreakdown,
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
                'expiring_soon_count' => $expiringSoonCount,
            ],
            'top_organizations' => $topOrganizations,
            'guest_members_total' => $guestMembersTotal,
        ];
    }

    /**
     * @return array{ministry: int, association: int, other: int}
     */
    private function countActiveOrganizationsByCategory(int $tenantId): array
    {
        $rows = Organization::query()
            ->forTenant($tenantId)
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->leftJoin('ma_organization_categories as oc', 'oc.id', '=', 'ma_organizations.category_id')
            ->selectRaw('LOWER(COALESCE(oc.code, \'\')) as category_code, COUNT(*) as total')
            ->groupBy('category_code')
            ->get();

        $ministry = 0;
        $association = 0;
        $other = 0;

        foreach ($rows as $row) {
            $code = (string) $row->category_code;
            $count = (int) $row->total;
            if ($code === 'ministry') {
                $ministry += $count;
            } elseif ($code === 'association') {
                $association += $count;
            } else {
                $other += $count;
            }
        }

        return [
            'ministry' => $ministry,
            'association' => $association,
            'other' => $other,
        ];
    }

    /**
     * Active organization counts per tenant category (spiritual, youth, etc.), including zero rows.
     *
     * @return list<array{category_id: string|null, code: string, name: string, count: int}>
     */
    private function countActiveOrganizationsByCategoryBreakdown(int $tenantId): array
    {
        $counts = Organization::query()
            ->forTenant($tenantId)
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $categories = OrganizationCategory::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $knownCategoryIds = $categories->pluck('id')->all();
        $rows = [];
        foreach ($categories as $category) {
            $rows[] = [
                'category_id' => $category->id,
                'code' => (string) $category->code,
                'name' => (string) $category->name,
                'count' => (int) ($counts[$category->id] ?? 0),
            ];
        }

        $uncategorized = 0;
        $orphanCategory = 0;
        foreach ($counts as $categoryId => $total) {
            if ($categoryId === null || $categoryId === '') {
                $uncategorized += (int) $total;

                continue;
            }
            if (! in_array($categoryId, $knownCategoryIds, true)) {
                $orphanCategory += (int) $total;
            }
        }

        if ($uncategorized > 0) {
            $rows[] = [
                'category_id' => null,
                'code' => 'uncategorized',
                'name' => 'Unassigned category',
                'count' => $uncategorized,
            ];
        }

        if ($orphanCategory > 0) {
            $rows[] = [
                'category_id' => null,
                'code' => 'orphan_category',
                'name' => 'Other categories',
                'count' => $orphanCategory,
            ];
        }

        return $rows;
    }

    /**
     * Active organization counts per tenant type (ministry, choir, fellowship, etc.), including zero rows.
     *
     * @return list<array{type_id: string|null, code: string, name: string, count: int}>
     */
    private function countActiveOrganizationsByType(int $tenantId): array
    {
        $counts = Organization::query()
            ->forTenant($tenantId)
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->selectRaw('type_id, COUNT(*) as total')
            ->groupBy('type_id')
            ->pluck('total', 'type_id');

        $types = OrganizationType::query()
            ->forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $knownTypeIds = $types->pluck('id')->all();
        $rows = [];
        foreach ($types as $type) {
            $rows[] = [
                'type_id' => $type->id,
                'code' => (string) $type->code,
                'name' => (string) $type->name,
                'count' => (int) ($counts[$type->id] ?? 0),
            ];
        }

        $uncategorized = 0;
        $orphanType = 0;
        foreach ($counts as $typeId => $total) {
            if ($typeId === null || $typeId === '') {
                $uncategorized += (int) $total;

                continue;
            }
            if (! in_array($typeId, $knownTypeIds, true)) {
                $orphanType += (int) $total;
            }
        }

        if ($uncategorized > 0) {
            $rows[] = [
                'type_id' => null,
                'code' => 'uncategorized',
                'name' => 'Unassigned type',
                'count' => $uncategorized,
            ];
        }

        if ($orphanType > 0) {
            $rows[] = [
                'type_id' => null,
                'code' => 'orphan_type',
                'name' => 'Other types',
                'count' => $orphanType,
            ];
        }

        return $rows;
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

    private function countExpiringSoonTerms(int $tenantId): int
    {
        $today = Carbon::today();
        $horizon = $today->copy()->addDays(self::EXPIRING_SOON_DAYS);

        return LeadershipTerm::query()
            ->forTenant($tenantId)
            ->where('status', LeadershipTerm::STATUS_ACTIVE)
            ->whereNotNull('effective_to')
            ->whereDate('effective_to', '>=', $today)
            ->whereDate('effective_to', '<=', $horizon)
            ->count();
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
