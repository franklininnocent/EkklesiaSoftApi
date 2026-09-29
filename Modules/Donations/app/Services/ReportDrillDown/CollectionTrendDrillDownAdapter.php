<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\MoneyMath;

class CollectionTrendDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'collection_trend';

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics,
        private readonly PaymentDrillDownQuery $paymentQuery
    ) {}

    public function graphId(): string
    {
        return self::GRAPH_ID;
    }

    public function supportedDataElementIds(): array
    {
        return ['collected'];
    }

    public function supportedSliceIds(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function supportedSliceIdsForTenant(int $tenantId, ?DashboardDateRange $range = null): array
    {
        return array_map(
            fn (array $b): string => $b['period'],
            $this->metrics->trendMonthBuckets($tenantId, $range)
        );
    }

    public function supportedDimensions(): array
    {
        return ['payment'];
    }

    public function supportedSorts(): array
    {
        return ['payment_date', 'amount', 'payer_name', 'method'];
    }

    public function supportedFilterKeys(): array
    {
        return ['method'];
    }

    public function build(int $tenantId, array $validated): array
    {
        $sliceId = (string) $validated['slice_id'];
        $range = $this->dashboardRange($tenantId, $validated);
        $bccFilter = $this->dashboardBccFilter($tenantId, $validated);
        $projectFilter = $this->dashboardProjectFilter($tenantId, $validated);
        if (! in_array($sliceId, $this->supportedSliceIdsForTenant($tenantId, $range), true)) {
            throw new \InvalidArgumentException('Invalid slice.');
        }

        $bucket = $this->metrics->trendBucketRange($tenantId, $sliceId, $range);
        if ($bucket === null) {
            throw new \InvalidArgumentException('Invalid period.');
        }

        $expectedAmount = $this->metrics->sumSucceededPayments(
            $tenantId,
            $bucket['start'],
            $bucket['end'],
            $bccFilter,
            $projectFilter
        );

        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $methodFilter = isset($filters['method']) ? (string) $filters['method'] : '';
        $sort = (string) ($validated['sort'] ?? 'payment_date');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $baseQuery = $this->metrics->succeededPaymentsInRangeScoped(
            $tenantId,
            $bucket['start'],
            $bucket['end'],
            $bccFilter,
            $projectFilter
        )->with(['family:id,family_name,head_of_family,family_code']);

        $listQuery = clone $baseQuery;
        $this->paymentQuery->applyPaymentSearch($listQuery, $search);
        if ($methodFilter !== '') {
            $listQuery->where('method', $methodFilter);
        }

        if (! in_array($sort, $this->supportedSorts(), true)) {
            throw new \InvalidArgumentException('Invalid sort field.');
        }

        $sortColumn = match ($sort) {
            'amount' => 'amount',
            'payer_name' => 'payer_name',
            'method' => 'method',
            default => 'payment_date',
        };

        $filteredAmount = MoneyMath::toApiNumber((clone $listQuery)->sum('amount'));
        $total = (int) (clone $listQuery)->count();

        $rows = $listQuery->orderBy($sortColumn, $direction)
            ->forPage($page, $perPage)
            ->get();

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => 'collected',
            'slice_id' => $sliceId,
            'dimension' => 'payment',
            'record_kind' => 'payment',
            'point_kind' => 'actual',
            'value_kind' => 'money',
            'title' => 'Collection trend → '.$sliceId,
            'why_this_number' => 'Successful collections grouped by payment date for this month.',
            'expected_amount' => $expectedAmount,
            'expected_count' => (int) (clone $baseQuery)->count(),
            'workspace_path' => '/donations/payments',
            'workspace_query' => [],
            'columns' => $this->paymentQuery->paymentColumns(),
            'supported_actions' => ['view_family', 'open_workspace'],
            'period_start' => $bucket['start'],
            'period_end' => $bucket['end'],
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
}
