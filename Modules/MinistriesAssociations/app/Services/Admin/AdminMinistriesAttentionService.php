<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use Illuminate\Support\Collection;

/**
 * Builds Attention Required cards for Ministries Insights Overview.
 */
class AdminMinistriesAttentionService
{
    private const SAMPLE_LIMIT = 5;

    /**
     * @param  Collection<int, array<string, mixed>>  $classified
     * @param  array{orgs_without_active_members: int, active_orgs_without_leadership: int, stale_active_orgs: int}  $health
     * @return list<array<string, mixed>>
     */
    public function build(Collection $classified, array $health): array
    {
        $items = [];

        $items[] = $this->tenantAttention(
            id: 'not_started',
            label: 'Not Started',
            severity: 'attention',
            description: 'Module enabled but no organizations created yet.',
            rows: $classified->where('not_started', true)->values(),
            filter: ['module_status' => 'enabled', 'adoption_status' => 'not_started'],
        );

        $items[] = $this->tenantAttention(
            id: 'inactive',
            label: 'Inactive',
            severity: 'attention',
            description: 'Activated tenants with no meaningful activity in the selected window.',
            rows: $classified->where('inactive', true)->values(),
            filter: ['adoption_status' => 'inactive'],
        );

        $items[] = $this->tenantAttention(
            id: 'declining',
            label: 'Declining Usage',
            severity: 'attention',
            description: 'Meaningful activity fell to half or less of the prior window.',
            rows: $classified->where('declining', true)->values(),
            filter: ['adoption_status' => 'declining'],
        );

        $items[] = $this->countAttention(
            id: 'orgs_without_members',
            label: 'Organizations Without Members',
            severity: 'attention',
            description: 'Organizations that currently have no active memberships.',
            count: $health['orgs_without_active_members'],
            href: '/platform/ministries/organizations?health=without_members',
            filter: ['health' => 'without_members'],
        );

        $items[] = $this->countAttention(
            id: 'orgs_without_leadership',
            label: 'Organizations Without Active Leadership',
            severity: 'attention',
            description: 'Active organizations with no active leadership assignment.',
            count: $health['active_orgs_without_leadership'],
            href: '/platform/ministries/organizations?health=without_leadership',
            filter: ['health' => 'without_leadership'],
        );

        $items[] = $this->countAttention(
            id: 'stale_orgs',
            label: 'Stale Active Organizations',
            severity: 'attention',
            description: 'Active organizations with no recent meaningful activity and no recent updates.',
            count: $health['stale_active_orgs'],
            href: '/platform/ministries/organizations?health=stale',
            filter: ['health' => 'stale'],
        );

        return array_values(array_filter(
            $items,
            static fn (array $item): bool => (int) $item['count'] > 0
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $filter
     * @return array<string, mixed>
     */
    private function tenantAttention(
        string $id,
        string $label,
        string $severity,
        string $description,
        Collection $rows,
        array $filter,
    ): array {
        $query = http_build_query($filter);

        return [
            'id' => $id,
            'label' => $label,
            'severity' => $severity,
            'description' => $description,
            'count' => $rows->count(),
            'filter' => $filter,
            'href' => '/platform/ministries/tenants'.($query !== '' ? '?'.$query : ''),
            'sample_tenants' => $rows->take(self::SAMPLE_LIMIT)->map(static function (array $row): array {
                return [
                    'id' => $row['tenant_id'],
                    'name' => $row['tenant_name'],
                    'slug' => $row['tenant_slug'],
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $filter
     * @return array<string, mixed>
     */
    private function countAttention(
        string $id,
        string $label,
        string $severity,
        string $description,
        int $count,
        string $href,
        array $filter,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'severity' => $severity,
            'description' => $description,
            'count' => $count,
            'filter' => $filter,
            'href' => $href,
            'sample_tenants' => [],
        ];
    }
}
