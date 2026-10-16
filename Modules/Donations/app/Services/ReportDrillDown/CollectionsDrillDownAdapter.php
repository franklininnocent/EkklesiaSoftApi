<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\MoneyMath;

class CollectionsDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'collections';

    /** @var array<string, string> */
    private const ELEMENT_SLICE_MAP = [
        'current_month_collected' => 'current_month',
        'previous_month_collected' => 'previous_month',
    ];

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
        return array_keys(self::ELEMENT_SLICE_MAP);
    }

    public function supportedSliceIds(): array
    {
        return ['current_month', 'previous_month'];
    }

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return self::ELEMENT_SLICE_MAP[$dataElementId] ?? null;
    }

    public function supportedDimensions(): array
    {
        return ['payment'];
    }

    /**
     * @return array<int, string>
     */
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
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $mapped = $this->sliceIdForElement($dataElementId);
        if ($mapped === null || $mapped !== $sliceId) {
            throw new \InvalidArgumentException('Invalid data element for this slice.');
        }

        $dashboardRange = $this->dashboardRange($tenantId, $validated);
        $bccFilter = $this->dashboardBccFilter($tenantId, $validated);
        $projectFilter = $this->dashboardProjectFilter($tenantId, $validated);
        $range = $this->metrics->collectionsSliceRange($tenantId, $sliceId, $dashboardRange);
        $expectedAmount = $this->metrics->sumSucceededPayments(
            $tenantId,
            $range['start'],
            $range['end'],
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
            $range['start'],
            $range['end'],
            $bccFilter,
            $projectFilter
        )->with(['family:id,family_name,head_of_family,family_code']);

        $unfiltered = $this->paymentQuery->unfilteredSummary(clone $baseQuery);

        $listQuery = clone $baseQuery;
        $this->paymentQuery->applyPaymentSearch($listQuery, $search);
        if ($methodFilter !== '') {
            $listQuery->where('method', $methodFilter);
        }

        $filteredAmount = MoneyMath::toApiNumber((clone $listQuery)->sum('amount'));
        $total = (int) (clone $listQuery)->count();

        $sortColumn = match ($sort) {
            'amount' => 'amount',
            'payer_name' => 'payer_name',
            'method' => 'method',
            default => 'payment_date',
        };
        if (! in_array($sort, $this->supportedSorts(), true)) {
            throw new \InvalidArgumentException('Invalid sort field.');
        }

        $rows = $listQuery->orderBy($sortColumn, $direction)
            ->forPage($page, $perPage)
            ->get();

        $title = $sliceId === 'current_month'
            ? ($dashboardRange !== null && $dashboardRange->preset !== DashboardDateRange::PRESET_THIS_MONTH
                ? 'Collections → Selected period'
                : 'Collections → This month')
            : ($dashboardRange !== null && $dashboardRange->comparisonMode === DashboardDateRange::COMPARISON_EQUAL_LENGTH_PRIOR
                ? 'Collections → Previous period'
                : 'Collections → Same days last month');

        $why = $sliceId === 'current_month'
            ? 'Gross succeeded payments in the selected inclusive date range through parish business today.'
            : 'Gross succeeded payments in the comparison window used for growth on the board.';

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => $dataElementId,
            'slice_id' => $sliceId,
            'dimension' => 'payment',
            'record_kind' => 'payment',
            'point_kind' => 'actual',
            'value_kind' => 'money',
            'title' => $title,
            'why_this_number' => $why,
            'expected_amount' => $expectedAmount,
            'expected_count' => $unfiltered['record_count'],
            'workspace_path' => '/donations/payments',
            'workspace_query' => [],
            'columns' => $this->paymentQuery->paymentColumns(),
            'supported_actions' => ['view_family', 'open_workspace'],
            'period_start' => $range['start'],
            'period_end' => $range['end'],
        ]);

        return [
            'context' => $context,
            'summary' => [
                'family_count' => 0,
                'record_count' => $total,
                'due_count' => 0,
                'amount_total' => $filteredAmount,
            ],
            'filter_options' => [
                'methods' => $this->methodFilterOptions($tenantId, $range['start'], $range['end'], $bccFilter, $projectFilter),
            ],
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
     * @return array<int, array{value: string, label: string}>
     */
    private function methodFilterOptions(
        int $tenantId,
        string $start,
        string $end,
        DashboardBccFilter $bccFilter,
        DashboardProjectFilter $projectFilter
    ): array {
        $methods = $this->metrics->succeededPaymentsInRangeScoped($tenantId, $start, $end, $bccFilter, $projectFilter)
            ->selectRaw('method, COUNT(*) as cnt')
            ->groupBy('method')
            ->orderBy('method')
            ->get();

        return $methods->map(fn ($row) => [
            'value' => (string) $row->method,
            'label' => ucfirst(str_replace('_', ' ', (string) $row->method)),
        ])->all();
    }
}
