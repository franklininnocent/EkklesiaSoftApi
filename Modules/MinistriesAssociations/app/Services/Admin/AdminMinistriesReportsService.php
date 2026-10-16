<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use InvalidArgumentException;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\MinistriesAuditLog;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesReportTypes;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform report summaries + CSV streaming for Ministries Insights.
 */
class AdminMinistriesReportsService
{
    private const EXPORT_LIMIT = 5000;

    private const PREVIEW_LIMIT = 25;

    public function __construct(
        private readonly AdminMinistriesTenantAnalyticsService $tenantAnalytics,
        private readonly AdminMinistriesAnalyticsService $analytics,
        private readonly AdminMinistriesHealthService $health,
        private readonly AdminMinistriesOrganizationsService $organizations,
    ) {
    }

    /**
     * @return list<array{type: string, title: string, description: string, windowed: bool}>
     */
    public function catalog(): array
    {
        return AdminMinistriesReportTypes::catalog();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(string $type, array $filters = []): array
    {
        $this->assertType($type);
        $window = $this->windowFromFilters($filters);

        return match ($type) {
            AdminMinistriesReportTypes::MODULE_ADOPTION => $this->moduleAdoptionSummary($window),
            AdminMinistriesReportTypes::TENANT_USAGE => $this->tenantUsageSummary($window),
            AdminMinistriesReportTypes::ORGANIZATION_OVERVIEW => $this->organizationOverviewSummary(),
            AdminMinistriesReportTypes::MEMBERSHIP_OVERVIEW => $this->membershipOverviewSummary(),
            AdminMinistriesReportTypes::LEADERSHIP_OVERVIEW => $this->leadershipOverviewSummary(),
            AdminMinistriesReportTypes::INACTIVE_NOT_STARTED => $this->inactiveNotStartedSummary($window),
            AdminMinistriesReportTypes::FEATURE_ADOPTION => $this->featureAdoptionSummary($window),
            AdminMinistriesReportTypes::DATA_HEALTH => $this->dataHealthSummary(),
            AdminMinistriesReportTypes::AUDIT => $this->auditSummary($window),
            default => throw new InvalidArgumentException('Unknown report type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(string $type, array $filters = []): StreamedResponse
    {
        $this->assertType($type);
        $window = $this->windowFromFilters($filters);
        [$headers, $rows] = match ($type) {
            AdminMinistriesReportTypes::MODULE_ADOPTION => $this->moduleAdoptionRows($window),
            AdminMinistriesReportTypes::TENANT_USAGE => $this->tenantUsageRows($window),
            AdminMinistriesReportTypes::ORGANIZATION_OVERVIEW => $this->organizationOverviewRows(),
            AdminMinistriesReportTypes::MEMBERSHIP_OVERVIEW => $this->membershipOverviewRows(),
            AdminMinistriesReportTypes::LEADERSHIP_OVERVIEW => $this->leadershipOverviewRows(),
            AdminMinistriesReportTypes::INACTIVE_NOT_STARTED => $this->inactiveNotStartedRows($window),
            AdminMinistriesReportTypes::FEATURE_ADOPTION => $this->featureAdoptionRows($window),
            AdminMinistriesReportTypes::DATA_HEALTH => $this->dataHealthRows(),
            AdminMinistriesReportTypes::AUDIT => $this->auditRows($window),
            default => throw new InvalidArgumentException('Unknown report type.'),
        };

        $filename = 'ministries-insights-'.$type.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function assertType(string $type): void
    {
        if (! AdminMinistriesReportTypes::isValid($type)) {
            throw new InvalidArgumentException('Unknown report type.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function windowFromFilters(array $filters): AdminMinistriesWindow
    {
        $days = isset($filters['window_days'])
            ? (int) $filters['window_days']
            : AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS;

        return AdminMinistriesWindow::fromDays($days);
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleAdoptionSummary(AdminMinistriesWindow $window): array
    {
        $adoption = $this->analytics->adoption($window);
        $rows = $this->tenantAnalytics->buildRows($window)->take(self::PREVIEW_LIMIT)->values();

        return [
            'type' => AdminMinistriesReportTypes::MODULE_ADOPTION,
            'title' => 'Module Adoption',
            'window' => $adoption['window'],
            'summary' => $adoption['funnel'],
            'rates' => $adoption['rates'],
            'preview' => $rows->map(fn (array $row) => $this->tenantPreview($row))->all(),
            'preview_truncated' => $this->tenantAnalytics->buildRows($window)->count() > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function moduleAdoptionRows(AdminMinistriesWindow $window): array
    {
        $rows = $this->tenantAnalytics->buildRows($window)->take(self::EXPORT_LIMIT);

        return [
            [
                'tenant_id', 'tenant_name', 'tenant_slug', 'module_status', 'adoption_status',
                'organizations', 'window_actions', 'prior_actions', 'usage_trend', 'activation_date',
            ],
            $rows->map(static fn (array $row): array => [
                $row['tenant_id'],
                $row['tenant_name'],
                $row['tenant_slug'],
                $row['module_status'],
                $row['primary_adoption_status'],
                $row['organizations_count'],
                $row['current_events'],
                $row['prior_events'],
                $row['usage_trend'],
                $row['activation_date'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantUsageSummary(AdminMinistriesWindow $window): array
    {
        $usage = $this->analytics->usage($window);

        return [
            'type' => AdminMinistriesReportTypes::TENANT_USAGE,
            'title' => 'Tenant Usage',
            'window' => $usage['window'],
            'summary' => $usage['summary'],
            'frequency_buckets' => $usage['frequency_buckets'],
            'trend_counts' => $usage['trend_counts'],
            'preview' => $usage['top_tenants'],
            'preview_truncated' => false,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function tenantUsageRows(AdminMinistriesWindow $window): array
    {
        $rows = $this->tenantAnalytics->buildRows($window)
            ->where('activated', true)
            ->sortByDesc('current_events')
            ->take(self::EXPORT_LIMIT)
            ->values();

        return [
            [
                'tenant_id', 'tenant_name', 'tenant_slug', 'window_actions', 'prior_actions',
                'active_users', 'usage_trend', 'adoption_status', 'last_activity_at',
            ],
            $rows->map(static fn (array $row): array => [
                $row['tenant_id'],
                $row['tenant_name'],
                $row['tenant_slug'],
                $row['current_events'],
                $row['prior_events'],
                $row['active_users_count'],
                $row['usage_trend'],
                $row['primary_adoption_status'],
                $row['last_activity_at'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationOverviewSummary(): array
    {
        $byStatus = Organization::query()
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $list = $this->organizations->list([
            'per_page' => self::PREVIEW_LIMIT,
            'page' => 1,
            'sort' => 'name',
            'direction' => 'asc',
        ]);

        return [
            'type' => AdminMinistriesReportTypes::ORGANIZATION_OVERVIEW,
            'title' => 'Organization Overview',
            'window' => null,
            'summary' => [
                'total' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[Organization::STATUS_ACTIVE] ?? 0),
                'inactive' => (int) ($byStatus[Organization::STATUS_INACTIVE] ?? 0),
            ],
            'preview' => collect($list['data'])->map(static function (array $row): array {
                return [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'status' => $row['status'],
                    'tenant_name' => $row['tenant']['name'] ?? null,
                    'members_count' => $row['members_count'],
                    'leaders_count' => $row['leaders_count'],
                    'health_flag' => $row['health_flag'],
                ];
            })->all(),
            'preview_truncated' => ($list['meta']['total'] ?? 0) > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function organizationOverviewRows(): array
    {
        $rows = Organization::query()
            ->with(['tenant:id,name,slug', 'category:id,name,code', 'type:id,name,code'])
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            [
                'organization_id', 'name', 'code', 'status', 'tenant_id', 'tenant_name',
                'category', 'type', 'created_at', 'updated_at',
            ],
            $rows->map(static fn (Organization $org): array => [
                $org->id,
                $org->name,
                $org->code,
                $org->status,
                $org->tenant_id,
                $org->tenant?->name,
                $org->category?->name,
                $org->type?->name,
                optional($org->created_at)?->toIso8601String(),
                optional($org->updated_at)?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipOverviewSummary(): array
    {
        $byStatus = OrganizationMembership::query()
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $bySource = OrganizationMembership::query()
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->selectRaw('member_source, COUNT(*) as total')
            ->groupBy('member_source')
            ->pluck('total', 'member_source');

        $preview = OrganizationMembership::query()
            ->with(['organization:id,name,code', 'tenant:id,name,slug'])
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->orderByDesc('joined_date')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(static fn (OrganizationMembership $m): array => [
                'id' => $m->id,
                'status' => $m->status,
                'member_source' => $m->member_source,
                'joined_date' => $m->joined_date?->toDateString(),
                'organization_name' => $m->organization?->name,
                'tenant_name' => $m->tenant?->name,
            ])
            ->all();

        return [
            'type' => AdminMinistriesReportTypes::MEMBERSHIP_OVERVIEW,
            'title' => 'Membership Overview',
            'window' => null,
            'summary' => [
                'current_total' => (int) $byStatus->sum(),
                'by_status' => $byStatus->map(static fn ($n) => (int) $n)->all(),
                'by_source' => $bySource->map(static fn ($n) => (int) $n)->all(),
            ],
            'preview' => $preview,
            'preview_truncated' => (int) $byStatus->sum() > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function membershipOverviewRows(): array
    {
        $rows = OrganizationMembership::query()
            ->with(['organization:id,name,code', 'tenant:id,name,slug'])
            ->whereNull('deleted_at')
            ->where('is_current', true)
            ->orderByDesc('joined_date')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            [
                'membership_id', 'tenant_id', 'tenant_name', 'organization_id', 'organization_name',
                'member_source', 'status', 'joined_date', 'is_current',
            ],
            $rows->map(static fn (OrganizationMembership $m): array => [
                $m->id,
                $m->tenant_id,
                $m->tenant?->name,
                $m->organization_id,
                $m->organization?->name,
                $m->member_source,
                $m->status,
                $m->joined_date?->toDateString(),
                $m->is_current ? 1 : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadershipOverviewSummary(): array
    {
        $byStatus = LeadershipTerm::query()
            ->whereNull('deleted_at')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $preview = LeadershipTerm::query()
            ->with(['organization:id,name', 'tenant:id,name', 'position:id,name'])
            ->whereNull('deleted_at')
            ->orderByDesc('effective_from')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(static fn (LeadershipTerm $term): array => [
                'id' => $term->id,
                'status' => $term->status,
                'effective_from' => $term->effective_from?->toDateString(),
                'effective_to' => $term->effective_to?->toDateString(),
                'position' => $term->position?->name,
                'organization' => $term->organization?->name,
                'tenant' => $term->tenant?->name,
            ])
            ->all();

        return [
            'type' => AdminMinistriesReportTypes::LEADERSHIP_OVERVIEW,
            'title' => 'Leadership Overview',
            'window' => null,
            'summary' => [
                'total' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[LeadershipTerm::STATUS_ACTIVE] ?? 0),
                'by_status' => $byStatus->map(static fn ($n) => (int) $n)->all(),
            ],
            'preview' => $preview,
            'preview_truncated' => (int) $byStatus->sum() > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function leadershipOverviewRows(): array
    {
        $rows = LeadershipTerm::query()
            ->with(['organization:id,name', 'tenant:id,name', 'position:id,name'])
            ->whereNull('deleted_at')
            ->orderByDesc('effective_from')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            [
                'term_id', 'tenant_id', 'tenant_name', 'organization_id', 'organization_name',
                'position', 'status', 'effective_from', 'effective_to',
            ],
            $rows->map(static fn (LeadershipTerm $term): array => [
                $term->id,
                $term->tenant_id,
                $term->tenant?->name,
                $term->organization_id,
                $term->organization?->name,
                $term->position?->name,
                $term->status,
                $term->effective_from?->toDateString(),
                $term->effective_to?->toDateString(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inactiveNotStartedSummary(AdminMinistriesWindow $window): array
    {
        $rows = $this->attentionRows($window);
        $preview = $rows->take(self::PREVIEW_LIMIT)->values();

        return [
            'type' => AdminMinistriesReportTypes::INACTIVE_NOT_STARTED,
            'title' => 'Inactive / Not Started Tenants',
            'window' => $this->windowPayload($window),
            'summary' => [
                'total' => $rows->count(),
                'not_started' => $rows->where('not_started', true)->count(),
                'inactive' => $rows->where('inactive', true)->count(),
                'declining' => $rows->where('declining', true)->count(),
            ],
            'preview' => $preview->map(fn (array $row) => $this->tenantPreview($row))->all(),
            'preview_truncated' => $rows->count() > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function inactiveNotStartedRows(AdminMinistriesWindow $window): array
    {
        $rows = $this->attentionRows($window)->take(self::EXPORT_LIMIT);

        return [
            [
                'tenant_id', 'tenant_name', 'tenant_slug', 'adoption_status',
                'organizations', 'window_actions', 'prior_actions', 'last_activity_at',
            ],
            $rows->map(static fn (array $row): array => [
                $row['tenant_id'],
                $row['tenant_name'],
                $row['tenant_slug'],
                $row['primary_adoption_status'],
                $row['organizations_count'],
                $row['current_events'],
                $row['prior_events'],
                $row['last_activity_at'],
            ])->all(),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function attentionRows(AdminMinistriesWindow $window)
    {
        return $this->tenantAnalytics->buildRows($window)
            ->filter(static function (array $row): bool {
                return ($row['module_status'] ?? null) === 'enabled'
                    && (
                        ($row['not_started'] ?? false)
                        || ($row['inactive'] ?? false)
                        || ($row['declining'] ?? false)
                    );
            })
            ->sortBy('tenant_name')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function featureAdoptionSummary(AdminMinistriesWindow $window): array
    {
        $features = $this->analytics->features($window);

        return [
            'type' => AdminMinistriesReportTypes::FEATURE_ADOPTION,
            'title' => 'Feature Adoption',
            'window' => $features['window'],
            'summary' => [
                'enabled_tenants' => $features['enabled_tenants'],
            ],
            'preview' => $features['categories'],
            'preview_truncated' => false,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function featureAdoptionRows(AdminMinistriesWindow $window): array
    {
        $features = $this->analytics->features($window);

        return [
            ['category', 'tenants_with_evidence', 'enabled_tenants', 'adoption_percent'],
            collect($features['categories'])->map(static fn (array $row): array => [
                $row['category'],
                $row['tenants_with_evidence'],
                $row['enabled_tenants'],
                $row['adoption_percent'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataHealthSummary(): array
    {
        $health = $this->health->summary();
        $withoutMembers = $this->organizations->list([
            'health' => 'without_members',
            'per_page' => self::PREVIEW_LIMIT,
            'page' => 1,
        ]);

        return [
            'type' => AdminMinistriesReportTypes::DATA_HEALTH,
            'title' => 'Data Health',
            'window' => null,
            'summary' => [
                'organizations' => $health['organizations'],
                'indicators' => $health['indicators'],
                'issue_total' => $health['issue_total'],
            ],
            'definitions' => $health['definitions'],
            'links' => $health['links'],
            'preview' => collect($withoutMembers['data'])->map(static function (array $row): array {
                return [
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'status' => $row['status'],
                    'tenant_name' => $row['tenant']['name'] ?? null,
                    'members_count' => $row['members_count'],
                    'leaders_count' => $row['leaders_count'],
                    'health_flag' => $row['health_flag'],
                ];
            })->all(),
            'preview_truncated' => ($withoutMembers['meta']['total'] ?? 0) > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function dataHealthRows(): array
    {
        $withoutMembers = collect($this->organizations->list([
            'health' => 'without_members',
            'per_page' => self::EXPORT_LIMIT,
            'page' => 1,
        ])['data'])->map(static fn (array $row): array => array_merge($row, ['issue' => 'without_members']));

        $withoutLeadership = collect($this->organizations->list([
            'health' => 'without_leadership',
            'per_page' => self::EXPORT_LIMIT,
            'page' => 1,
        ])['data'])->map(static fn (array $row): array => array_merge($row, ['issue' => 'without_leadership']));

        $stale = collect($this->organizations->list([
            'health' => 'stale',
            'per_page' => self::EXPORT_LIMIT,
            'page' => 1,
        ])['data'])->map(static fn (array $row): array => array_merge($row, ['issue' => 'stale']));

        $merged = $withoutMembers
            ->concat($withoutLeadership)
            ->concat($stale)
            ->take(self::EXPORT_LIMIT);

        return [
            [
                'issue', 'organization_id', 'name', 'code', 'status', 'tenant_id', 'tenant_name',
                'members_count', 'leaders_count', 'last_activity_at', 'health_flag',
            ],
            $merged->map(static fn (array $row): array => [
                $row['issue'],
                $row['id'],
                $row['name'],
                $row['code'],
                $row['status'],
                $row['tenant']['id'] ?? null,
                $row['tenant']['name'] ?? null,
                $row['members_count'],
                $row['leaders_count'],
                $row['last_activity_at'],
                $row['health_flag'],
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSummary(AdminMinistriesWindow $window): array
    {
        $total = MinistriesAuditLog::query()
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->count();

        $byEvent = MinistriesAuditLog::query()
            ->selectRaw('event, COUNT(*) as total')
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->groupBy('event')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(static fn ($row): array => [
                'event' => (string) $row->event,
                'total' => (int) $row->total,
            ])
            ->all();

        $preview = MinistriesAuditLog::query()
            ->with(['tenant:id,name,slug', 'actor:id,name'])
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->orderByDesc('created_at')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(static function (MinistriesAuditLog $log): array {
                return [
                    'id' => $log->id,
                    'occurred_at' => optional($log->created_at)?->toIso8601String(),
                    'event' => $log->event,
                    'tenant_name' => $log->tenant?->name,
                    'actor_name' => $log->actor?->name,
                ];
            })
            ->all();

        return [
            'type' => AdminMinistriesReportTypes::AUDIT,
            'title' => 'Audit Activity',
            'window' => $this->windowPayload($window),
            'summary' => [
                'meaningful_events' => $total,
                'top_events' => $byEvent,
            ],
            'preview' => $preview,
            'preview_truncated' => $total > self::PREVIEW_LIMIT,
        ];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function auditRows(AdminMinistriesWindow $window): array
    {
        $rows = MinistriesAuditLog::query()
            ->with(['tenant:id,name,slug', 'actor:id,name'])
            ->whereIn('event', AdminMinistriesDefinitions::MEANINGFUL_EVENTS)
            ->where('created_at', '>=', $window->currentStart)
            ->where('created_at', '<=', $window->currentEnd)
            ->orderByDesc('created_at')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        return [
            [
                'id', 'occurred_at', 'tenant_id', 'tenant_name', 'event', 'target_type',
                'target_id', 'organization_id', 'actor_id', 'actor_name',
            ],
            $rows->map(static fn (MinistriesAuditLog $log): array => [
                $log->id,
                optional($log->created_at)?->toIso8601String(),
                $log->tenant_id,
                $log->tenant?->name,
                $log->event,
                $log->target_type,
                $log->target_id,
                $log->organization_id,
                $log->actor_user_id,
                $log->actor?->name,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function tenantPreview(array $row): array
    {
        return [
            'tenant_id' => $row['tenant_id'],
            'tenant_name' => $row['tenant_name'],
            'tenant_slug' => $row['tenant_slug'],
            'module_status' => $row['module_status'],
            'adoption_status' => $row['primary_adoption_status'],
            'organizations_count' => $row['organizations_count'],
            'window_actions' => $row['current_events'],
            'prior_actions' => $row['prior_events'],
            'usage_trend' => $row['usage_trend'],
            'last_activity_at' => $row['last_activity_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function windowPayload(AdminMinistriesWindow $window): array
    {
        return [
            'days' => $window->days,
            'current_start' => $window->currentStart->toIso8601String(),
            'current_end' => $window->currentEnd->toIso8601String(),
            'prior_start' => $window->priorStart->toIso8601String(),
            'prior_end' => $window->priorEnd->toIso8601String(),
        ];
    }
}
