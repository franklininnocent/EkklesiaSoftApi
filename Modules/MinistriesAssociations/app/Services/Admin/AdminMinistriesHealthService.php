<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;

class AdminMinistriesHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $indicators = $this->indicators();

        $totalOrgs = Organization::query()->whereNull('deleted_at')->count();
        $activeOrgs = Organization::query()
            ->whereNull('deleted_at')
            ->where('status', Organization::STATUS_ACTIVE)
            ->count();
        $inactiveOrgs = Organization::query()
            ->whereNull('deleted_at')
            ->where('status', Organization::STATUS_INACTIVE)
            ->count();

        return [
            'organizations' => [
                'total' => $totalOrgs,
                'active' => $activeOrgs,
                'inactive' => $inactiveOrgs,
            ],
            'indicators' => $indicators,
            'issue_total' => $indicators['orgs_without_active_members']
                + $indicators['active_orgs_without_leadership']
                + $indicators['stale_active_orgs'],
            'definitions' => [
                'orgs_without_active_members' => 'Organizations with no current active memberships.',
                'active_orgs_without_leadership' => 'Active organizations with no active leadership term.',
                'stale_active_orgs' => 'Active organizations with no update and no meaningful activity within '
                    .AdminMinistriesDefinitions::STALE_ORG_DAYS
                    .' days.',
            ],
            'links' => [
                'without_members' => '/platform/ministries/organizations?health=without_members',
                'without_leadership' => '/platform/ministries/organizations?health=without_leadership',
                'stale' => '/platform/ministries/organizations?health=stale',
            ],
        ];
    }

    /**
     * @return array{orgs_without_active_members: int, active_orgs_without_leadership: int, stale_active_orgs: int}
     */
    public function indicators(): array
    {
        $orgsWithoutMembers = Organization::query()
            ->whereNull('ma_organizations.deleted_at')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('ma_memberships')
                    ->whereColumn('ma_memberships.organization_id', 'ma_organizations.id')
                    ->where('ma_memberships.is_current', true)
                    ->where('ma_memberships.status', OrganizationMembership::STATUS_ACTIVE)
                    ->whereNull('ma_memberships.deleted_at');
            })
            ->count();

        $activeOrgsWithoutLeadership = Organization::query()
            ->whereNull('ma_organizations.deleted_at')
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('ma_leadership_terms')
                    ->whereColumn('ma_leadership_terms.organization_id', 'ma_organizations.id')
                    ->where('ma_leadership_terms.status', LeadershipTerm::STATUS_ACTIVE)
                    ->whereNull('ma_leadership_terms.deleted_at');
            })
            ->count();

        $staleCutoff = now('UTC')->subDays(AdminMinistriesDefinitions::STALE_ORG_DAYS);
        $staleActiveOrgs = Organization::query()
            ->whereNull('ma_organizations.deleted_at')
            ->where('ma_organizations.status', Organization::STATUS_ACTIVE)
            ->where('ma_organizations.updated_at', '<', $staleCutoff)
            ->whereNotExists(function ($query) use ($staleCutoff): void {
                $query->select(DB::raw(1))
                    ->from('ma_audit_logs')
                    ->whereColumn('ma_audit_logs.organization_id', 'ma_organizations.id')
                    ->whereIn('ma_audit_logs.event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
                    ->where('ma_audit_logs.created_at', '>=', $staleCutoff);
            })
            ->count();

        return [
            'orgs_without_active_members' => $orgsWithoutMembers,
            'active_orgs_without_leadership' => $activeOrgsWithoutLeadership,
            'stale_active_orgs' => $staleActiveOrgs,
        ];
    }
}
