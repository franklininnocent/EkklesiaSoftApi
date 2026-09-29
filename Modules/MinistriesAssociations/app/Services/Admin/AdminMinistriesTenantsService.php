<?php

namespace Modules\MinistriesAssociations\Services\Admin;

use InvalidArgumentException;
use Modules\MinistriesAssociations\Support\AdminMinistriesDefinitions;
use Modules\MinistriesAssociations\Support\AdminMinistriesWindow;
use Modules\Tenants\Models\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminMinistriesTenantsService
{
    private const EXPORT_LIMIT = 5000;

    public function __construct(
        private readonly AdminMinistriesTenantAnalyticsService $analytics,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|string>, window: array<string, mixed>}
     */
    public function list(array $filters): array
    {
        $window = AdminMinistriesWindow::fromDays(
            isset($filters['window_days']) ? (int) $filters['window_days'] : AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS
        );

        $rows = $this->analytics->buildRows($window);
        $rows = $this->analytics->filterRows($rows, $filters);
        $rows = $this->analytics->sortRows(
            $rows,
            isset($filters['sort']) ? (string) $filters['sort'] : 'tenant_name',
            isset($filters['direction']) ? (string) $filters['direction'] : 'asc',
        );

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $paginated = $this->analytics->paginate($rows, $page, $perPage);

        return [
            'data' => array_map([$this, 'listItem'], $paginated['data']),
            'meta' => $paginated['meta'],
            'window' => [
                'days' => $window->days,
                'current_start' => $window->currentStart->toIso8601String(),
                'current_end' => $window->currentEnd->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(int $tenantId, int $windowDays = AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS): array
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $window = AdminMinistriesWindow::fromDays($windowDays);
        $row = $this->analytics->buildRows($window)->firstWhere('tenant_id', $tenantId);
        if ($row === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $usage = [];
        foreach ([7, 30, 90] as $days) {
            $w = AdminMinistriesWindow::fromDays($days);
            $events = (int) ($this->analytics->eventCountsByTenant($w->currentStart, $w->currentEnd)[$tenantId] ?? 0);
            $prior = (int) ($this->analytics->eventCountsByTenant($w->priorStart, $w->priorEnd)[$tenantId] ?? 0);
            $usage[(string) $days] = [
                'window_days' => $days,
                'meaningful_actions' => $events,
                'prior_meaningful_actions' => $prior,
                'active_days' => $this->analytics->activeDaysForTenant($tenantId, $w),
                'usage_trend' => $this->analytics->trend($events, $prior),
            ];
        }

        return [
            'summary' => $this->listItem($row),
            'usage' => $usage,
            'feature_adoption' => $this->analytics->featureAdoptionForTenant($tenantId, $window),
            'health' => $row['health'],
            'recent_actions' => $this->analytics->recentActivityForTenant($tenantId),
            'window' => [
                'days' => $window->days,
                'current_start' => $window->currentStart->toIso8601String(),
                'current_end' => $window->currentEnd->toIso8601String(),
            ],
            'definitions' => AdminMinistriesDefinitions::toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function exportCsv(array $filters): StreamedResponse
    {
        $window = AdminMinistriesWindow::fromDays(
            isset($filters['window_days']) ? (int) $filters['window_days'] : AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS
        );

        $rows = $this->analytics->buildRows($window);
        $rows = $this->analytics->filterRows($rows, $filters);
        $rows = $this->analytics->sortRows(
            $rows,
            isset($filters['sort']) ? (string) $filters['sort'] : 'tenant_name',
            isset($filters['direction']) ? (string) $filters['direction'] : 'asc',
        )->take(self::EXPORT_LIMIT);

        $filename = 'ministries-insights-tenants-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'tenant_id',
                'tenant_name',
                'tenant_slug',
                'module_status',
                'adoption_status',
                'health_flag',
                'organizations',
                'members',
                'leaders',
                'active_users',
                'window_actions',
                'prior_actions',
                'usage_trend',
                'last_activity_at',
                'activation_date',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['tenant_id'],
                    $row['tenant_name'],
                    $row['tenant_slug'],
                    $row['module_status'],
                    $row['primary_adoption_status'],
                    $row['health_flag'],
                    $row['organizations_count'],
                    $row['members_count'],
                    $row['leaders_count'],
                    $row['active_users_count'],
                    $row['current_events'],
                    $row['prior_events'],
                    $row['usage_trend'],
                    $row['last_activity_at'],
                    $row['activation_date'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function listItem(array $row): array
    {
        return [
            'tenant_id' => $row['tenant_id'],
            'tenant_name' => $row['tenant_name'],
            'tenant_slug' => $row['tenant_slug'],
            'module_status' => $row['module_status'],
            'adoption_status' => $row['primary_adoption_status'],
            'health_flag' => $row['health_flag'],
            'organizations_count' => $row['organizations_count'],
            'members_count' => $row['members_count'],
            'leaders_count' => $row['leaders_count'],
            'active_users_count' => $row['active_users_count'],
            'window_actions' => $row['current_events'],
            'prior_actions' => $row['prior_events'],
            'usage_trend' => $row['usage_trend'],
            'last_activity_at' => $row['last_activity_at'],
            'activation_date' => $row['activation_date'],
            'health' => $row['health'],
            'flags' => [
                'not_started' => $row['not_started'],
                'activated' => $row['activated'],
                'active' => $row['active'],
                'highly_engaged' => $row['highly_engaged'],
                'inactive' => $row['inactive'],
                'declining' => $row['declining'],
            ],
        ];
    }
}
