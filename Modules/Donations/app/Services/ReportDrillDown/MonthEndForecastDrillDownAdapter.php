<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\CollectionForecastService;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\DashboardDateRange;

class MonthEndForecastDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'month_end_forecast';

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics,
        private readonly CollectionForecastService $forecastService,
        private readonly PaymentDrillDownQuery $paymentQuery
    ) {}

    public function graphId(): string
    {
        return self::GRAPH_ID;
    }

    public function supportedDataElementIds(): array
    {
        return ['collected', 'current_month_projection'];
    }

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return match ($dataElementId) {
            'collected' => null,
            'current_month_projection' => 'current_month_projection',
            default => null,
        };
    }

    public function supportedSliceIds(): array
    {
        return ['current_month_projection'];
    }

    /**
     * @return array<int, string>
     */
    public function supportedSliceIdsForTenant(int $tenantId, ?DashboardDateRange $range = null): array
    {
        $periods = array_map(
            fn (array $row): string => (string) $row['period'],
            array_slice($this->forecastService->build($tenantId, 1)['history'] ?? [], -6)
        );

        return array_values(array_unique(array_merge($periods, ['current_month_projection'])));
    }

    public function supportedDimensions(): array
    {
        return ['payment', 'none'];
    }

    public function supportedSorts(): array
    {
        return ['payment_date', 'amount', 'payer_name', 'method'];
    }

    public function build(int $tenantId, array $validated): array
    {
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $forecast = $this->forecastService->build($tenantId, 3);
        $signals = $forecast['signals'] ?? [];

        if ($dataElementId === 'current_month_projection' || $sliceId === 'current_month_projection') {
            return $this->buildProjectionMethodology($tenantId, $forecast, $validated);
        }

        if (! in_array($sliceId, $this->supportedSliceIdsForTenant($tenantId), true)) {
            throw new \InvalidArgumentException('Invalid slice.');
        }

        $range = $this->metrics->trendBucketRange($tenantId, $sliceId);
        if ($range === null) {
            throw new \InvalidArgumentException('Invalid period.');
        }

        $expectedAmount = $this->metrics->sumSucceededPayments($tenantId, $range['start'], $range['end']);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $sort = (string) ($validated['sort'] ?? 'payment_date');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $baseQuery = $this->metrics->succeededPaymentsInRange($tenantId, $range['start'], $range['end'])
            ->with(['family:id,family_name,head_of_family,family_code']);
        $listQuery = clone $baseQuery;
        $this->paymentQuery->applyPaymentSearch($listQuery, $search);

        if (! in_array($sort, $this->supportedSorts(), true)) {
            throw new \InvalidArgumentException('Invalid sort field.');
        }

        $sortColumn = match ($sort) {
            'amount' => 'amount',
            'payer_name' => 'payer_name',
            'method' => 'method',
            default => 'payment_date',
        };

        $total = (int) (clone $listQuery)->count();
        $filteredAmount = (float) (clone $listQuery)->sum('amount');
        $rows = $listQuery->orderBy($sortColumn, $direction)->forPage($page, $perPage)->get();

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => 'collected',
            'slice_id' => $sliceId,
            'dimension' => 'payment',
            'record_kind' => 'payment',
            'point_kind' => 'actual',
            'value_kind' => 'money',
            'title' => 'Month-end forecast → Collected '.$sliceId,
            'why_this_number' => 'Actual successful collections for this month (not a projection).',
            'expected_amount' => $expectedAmount,
            'expected_count' => (int) (clone $baseQuery)->count(),
            'workspace_path' => '/donations/payments',
            'workspace_query' => [],
            'columns' => $this->paymentQuery->paymentColumns(),
            'supported_actions' => ['view_family', 'open_workspace'],
            'methodology' => null,
        ]);

        return [
            'context' => $context,
            'summary' => [
                'family_count' => 0,
                'record_count' => $total,
                'due_count' => 0,
                'amount_total' => $filteredAmount,
            ],
            'filter_options' => ['methods' => []],
            'data' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'data' => $this->paymentQuery->mapPaymentRows($rows),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $forecast
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildProjectionMethodology(int $tenantId, array $forecast, array $validated): array
    {
        $signals = $forecast['signals'] ?? [];
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => 'current_month_projection',
            'slice_id' => 'current_month_projection',
            'dimension' => 'none',
            'record_kind' => 'none',
            'point_kind' => 'forecast',
            'value_kind' => 'money',
            'title' => 'Month-end forecast → Projected month-end',
            'why_this_number' => 'This is a forecast based on collections so far and daily pace — not recorded payments.',
            'expected_amount' => (float) ($signals['current_month_projection'] ?? 0),
            'expected_count' => 0,
            'workspace_path' => '/donations',
            'workspace_query' => [],
            'columns' => [],
            'supported_actions' => [],
            'methodology' => [
                'method' => $forecast['method'] ?? 'moving_average_with_pace',
                'steps' => [
                    'Average the last three months of collections (including the current partial month).',
                    'Divide month-to-date collected by elapsed days in the parish month (minimum 1).',
                    'Multiply daily pace by days in the parish month for the projected month-end total.',
                ],
                'inputs' => [
                    'moving_average_3m' => $signals['moving_average_3m'] ?? 0,
                    'current_month_collected' => $signals['current_month_collected'] ?? 0,
                    'elapsed_days' => $signals['elapsed_days'] ?? 1,
                    'days_in_month' => $signals['days_in_month'] ?? 30,
                    'daily_pace' => $signals['daily_pace'] ?? 0,
                    'source_periods' => $signals['moving_average_source_periods'] ?? [],
                ],
            ],
        ]);

        return [
            'context' => $context,
            'summary' => [
                'family_count' => 0,
                'record_count' => 0,
                'due_count' => 0,
                'amount_total' => 0,
            ],
            'filter_options' => [],
            'data' => $this->emptyPagination($page, $perPage),
        ];
    }
}
