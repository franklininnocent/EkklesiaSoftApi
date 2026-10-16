<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\DonationDashboardService;
use Modules\Donations\Services\ExecutiveReportMetricsService;

class CollectionSnapshotDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'collection_snapshot';

    /** @var array<string, string> */
    private const ELEMENT_SLICE_MAP = [
        'expected' => 'expected',
        'collected' => 'collected',
        'outstanding' => 'outstanding',
        'collection_rate' => 'collection_rate',
    ];

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics,
        private readonly DonationDashboardService $dashboardService
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
        return array_values(array_unique(self::ELEMENT_SLICE_MAP));
    }

    public function sliceIdForElement(string $dataElementId): ?string
    {
        return self::ELEMENT_SLICE_MAP[$dataElementId] ?? null;
    }

    public function supportedSorts(): array
    {
        return ['expected'];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function build(int $tenantId, array $validated): array
    {
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $mapped = $this->sliceIdForElement($dataElementId);
        if ($mapped === null || $mapped !== $sliceId) {
            throw new \InvalidArgumentException('Invalid data element for this slice.');
        }

        $dashboardRange = $this->dashboardRange($tenantId, $validated);
        $summary = $this->dashboardService->getSummary($tenantId, $dashboardRange);
        $outstanding = (float) ($summary['totals']['pending_dues'] ?? 0);
        $snapshot = $this->metrics->collectionSnapshot($tenantId, $outstanding, $dashboardRange);

        $expectedAmount = match ($sliceId) {
            'expected' => (float) $snapshot['expected'],
            'collected' => (float) $snapshot['collected'],
            'outstanding' => (float) $snapshot['outstanding'],
            'collection_rate' => (float) ($snapshot['collection_rate_pct'] ?? 0),
            default => 0.0,
        };

        $valueKind = $sliceId === 'collection_rate' ? 'score' : 'money';
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $methodology = [
            'expected_window' => 'Due dates from month start through parish business today',
            'collected' => 'Succeeded payments allocated to contribution dues (month-to-date through business today)',
            'outstanding' => 'Collectable mandatory dues as of business today',
            'snapshot' => $snapshot,
        ];

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => $dataElementId,
            'slice_id' => $sliceId,
            'dimension' => 'none',
            'record_kind' => 'none',
            'point_kind' => 'kpi',
            'value_kind' => $valueKind,
            'title' => 'Collection snapshot → '.$sliceId,
            'why_this_number' => 'Leadership snapshot of expected dues through today versus due-allocated collections.',
            'expected_amount' => $expectedAmount,
            'expected_count' => 0,
            'workspace_path' => '/donations',
            'workspace_query' => [],
            'columns' => [],
            'supported_actions' => [],
            'methodology' => $methodology,
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
