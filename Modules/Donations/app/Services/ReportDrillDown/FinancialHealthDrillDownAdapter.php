<?php

namespace Modules\Donations\Services\ReportDrillDown;

use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Services\FinancialHealthService;

class FinancialHealthDrillDownAdapter extends AbstractReportDrillDownAdapter implements ReportDrillDownAdapter
{
    private const GRAPH_ID = 'financial_health';

    /** @var array<string, string> */
    private const ELEMENT_SLICE_MAP = [
        'overall_score' => 'overall_score',
        'family_engagement' => 'family_engagement',
        'overdue_health' => 'overdue_health',
        'project_funding' => 'project_funding',
        'growth_health' => 'growth_health',
        'collection_performance' => 'collection_performance',
    ];

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics,
        private readonly FinancialHealthService $healthService,
        private readonly FamilyParticipationDrillDownAdapter $participationAdapter,
        private readonly OutstandingOverdueDrillDownAdapter $overdueAdapter
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
        return [
            'family_name',
            'family_code',
            'bcc_name',
            'outstanding_amount',
            'due_count',
            'oldest_due_date',
            'days_overdue',
            'funding_percentage',
        ];
    }

    public function build(int $tenantId, array $validated): array
    {
        $dataElementId = (string) $validated['data_element_id'];
        $sliceId = (string) $validated['slice_id'];
        $mapped = $this->sliceIdForElement($dataElementId);
        if ($mapped === null || $mapped !== $sliceId) {
            throw new \InvalidArgumentException('Invalid data element for this slice.');
        }

        if ($sliceId === 'family_engagement') {
            $activeFamilies = $this->metrics->countActiveFamilies($tenantId);
            $participatingCount = $this->metrics->countDistinctParticipatingFamilies($tenantId);
            $participationRate = $this->metrics->participationRatePct($tenantId);
            $payload = $this->coerceSortForAdapter($validated, $this->participationAdapter->supportedSorts(), 'family_name');
            $payload['data_element_id'] = 'participating_families';
            $payload['slice_id'] = 'participating';
            $result = $this->participationAdapter->build($tenantId, $payload);
            $result['context']['graph_id'] = self::GRAPH_ID;
            $result['context']['data_element_id'] = $dataElementId;
            $result['context']['slice_id'] = $sliceId;
            $result['context']['point_kind'] = 'actual';
            $result['context']['value_kind'] = 'score';
            $result['context']['expected_amount'] = min(100, max(0, $participationRate));
            $result['context']['title'] = 'Financial health → Family engagement';
            $result['context']['why_this_number'] = sprintf(
                'Participation rate is %s%% (%s of %s active families with at least one successful payment in the last 90 parish days). This list names those contributing families only—not families with dues but no recent payment. Family engagement is weighted 35%% in the overall health score.',
                number_format($participationRate, 1),
                number_format($participatingCount),
                number_format($activeFamilies)
            );

            return $result;
        }

        if ($sliceId === 'overdue_health') {
            $payload = $this->coerceSortForAdapter($validated, $this->overdueAdapter->supportedSorts(), 'outstanding_amount');
            $payload['graph_id'] = 'outstanding_overdue';
            $payload['data_element_id'] = 'overdue_amount';
            $payload['slice_id'] = 'overdue';

            $result = $this->overdueAdapter->build($tenantId, $payload);
            $healthCtx = $this->metrics->executiveHealthDrillContext($tenantId);
            $outstanding = (float) $healthCtx['pending_dues'];
            $overdueAmount = (float) $healthCtx['overdue_amount'];
            $overdueRatioPct = (float) $healthCtx['overdue_ratio_pct'];
            $factorScore = (float) $healthCtx['factors']['overdue_health'];

            $result['context']['graph_id'] = self::GRAPH_ID;
            $result['context']['data_element_id'] = $dataElementId;
            $result['context']['slice_id'] = $sliceId;
            $result['context']['point_kind'] = 'kpi';
            $result['context']['value_kind'] = 'score';
            $result['context']['expected_amount'] = $factorScore;
            $result['context']['title'] = 'Financial health → Overdue balance health';
            $result['context']['why_this_number'] = 'Score is 100 minus the share of outstanding balance that is overdue. Families listed are overdue for follow-up.';
            $result['context']['methodology'] = [
                'formula' => '100 − overdue_ratio_pct',
                'inputs' => [
                    'overdue_ratio_pct' => $overdueRatioPct,
                    'pending_dues' => $outstanding,
                    'overdue_amount' => $overdueAmount,
                ],
            ];

            return $result;
        }

        if ($sliceId === 'project_funding') {
            return $this->buildProjectFunding($tenantId, $validated);
        }

        return $this->buildKpiOnly($tenantId, $sliceId, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildKpiOnly(int $tenantId, string $sliceId, array $validated): array
    {
        $healthCtx = $this->metrics->executiveHealthDrillContext($tenantId);
        $factors = $healthCtx['factors'];
        $growthPct = (float) $healthCtx['collection_growth_pct'];
        $growthAnalysis = $healthCtx['collection_growth_analysis'] ?? [];
        $churchScore = $this->healthService->buildChurchScore([
            'participation_rate' => $healthCtx['participation_rate'],
            'overdue_ratio_pct' => $healthCtx['overdue_ratio_pct'],
            'project_momentum_pct' => $healthCtx['project_momentum_pct'],
            'project_applicable' => (bool) ($healthCtx['project_applicable'] ?? true),
            'collection_growth_pct' => $growthPct,
            'growth_health_score' => (float) ($healthCtx['growth_health_score'] ?? $factors['growth_health']),
            'overdue_family_count' => $healthCtx['overdue_family_count'],
            'current_month_collected' => (float) ($healthCtx['current_month_collected'] ?? 0),
        ]);
        $weights = $this->metrics->stewardshipHealthWeightBreakdown((bool) ($healthCtx['project_applicable'] ?? true));

        $expectedScore = match ($sliceId) {
            'overall_score' => (float) ($churchScore['score'] ?? 0),
            'growth_health' => (float) $factors['growth_health'],
            'collection_performance' => (float) $healthCtx['collection_performance_score'],
            default => 0.0,
        };

        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $methodology = match ($sliceId) {
            'overall_score' => [
                'weights' => collect($weights)->mapWithKeys(fn (array $row) => [$row['key'] => $row['weight_pct']])->all(),
                'factors' => $factors,
                'overall' => $churchScore,
            ],
            'growth_health' => [
                'formula' => '50 + (comparable collection_growth_pct / 2), clamped 0–100; neutral 50 when no prior period or tiny prior base',
                'collection_growth_pct' => $growthPct,
                'growth_analysis' => $growthAnalysis,
            ],
            'collection_performance' => [
                'note' => 'Not part of the 100-point health score.',
                'formula' => '60% growth score + 40% plan compliance',
                'score' => $expectedScore,
            ],
            default => [],
        };

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => (string) $validated['data_element_id'],
            'slice_id' => $sliceId,
            'dimension' => 'none',
            'record_kind' => 'none',
            'point_kind' => 'kpi',
            'value_kind' => 'score',
            'title' => 'Financial health → '.$sliceId,
            'why_this_number' => 'Calculated score from parish stewardship metrics — not a payment total.',
            'expected_amount' => $expectedScore,
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildProjectFunding(int $tenantId, array $validated): array
    {
        $projects = $this->metrics->executiveProjectSummaries($tenantId);
        $expectedScore = $this->metrics->averageProjectFundingPct($projects);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($validated['per_page'] ?? 20)));

        $context = $this->enrichContext($tenantId, [
            'graph_id' => self::GRAPH_ID,
            'data_element_id' => 'project_funding',
            'slice_id' => 'project_funding',
            'dimension' => 'project',
            'record_kind' => 'project',
            'point_kind' => 'actual',
            'value_kind' => 'score',
            'title' => 'Financial health → Project funding',
            'why_this_number' => 'Average funding progress across up to five active projects (same as the health bar).',
            'expected_amount' => $expectedScore,
            'expected_count' => count($projects),
            'workspace_path' => '/donations/projects',
            'workspace_query' => [],
            'columns' => [
                ['key' => 'name', 'label' => 'Project'],
                ['key' => 'collected', 'label' => 'Collected'],
                ['key' => 'target', 'label' => 'Target'],
                ['key' => 'funding_percentage', 'label' => 'Funded %'],
            ],
            'supported_actions' => ['open_workspace'],
            'methodology' => [
                'aggregation' => 'Average of funding_percentage for active projects (max 5, most recently updated).',
            ],
        ]);

        return [
            'context' => $context,
            'summary' => [
                'family_count' => 0,
                'record_count' => count($projects),
                'due_count' => 0,
                'amount_total' => 0,
            ],
            'filter_options' => [],
            'data' => [
                'current_page' => 1,
                'per_page' => $perPage,
                'total' => count($projects),
                'last_page' => 1,
                'data' => array_map(fn (array $p) => [
                    'project_id' => $p['project_id'],
                    'name' => $p['name'],
                    'code' => $p['code'],
                    'collected' => $p['collected'],
                    'target_amount' => $p['target_amount'],
                    'funding_percentage' => $p['funding_percentage'],
                ], $projects),
            ],
        ];
    }

    /**
     * Leadership modal defaults (e.g. outstanding_amount) may not match delegated list adapters.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $allowedSorts
     * @return array<string, mixed>
     */
    private function coerceSortForAdapter(array $validated, array $allowedSorts, string $fallback): array
    {
        $sort = (string) ($validated['sort'] ?? $fallback);
        if ($allowedSorts !== [] && ! in_array($sort, $allowedSorts, true)) {
            $validated['sort'] = $fallback;
        }

        return $validated;
    }
}
