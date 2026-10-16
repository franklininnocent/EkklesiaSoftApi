<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Collection;
use Modules\Donations\Models\Donation;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\Reports\DonationReportExportOrchestrator;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Support\ChurchMoneyFormatter;

class DonationReportService
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly CollectionForecastService $forecastService,
        private readonly DioceseRollupDashboardService $rollupService,
        private readonly ExecutiveReportMetricsService $executiveMetrics,
        private readonly ParishExpenseService $parishExpenseService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildExecutiveNarrative(
        int $tenantId,
        ?DashboardDateRange $range = null,
        ?DashboardBccFilter $bccFilter = null,
        ?DashboardProjectFilter $projectFilter = null
    ): array {
        $bccFilter ??= DashboardBccFilter::none();
        $projectFilter ??= DashboardProjectFilter::none();
        $summary = $this->dashboardService->getSummary($tenantId, $range, $bccFilter, $projectFilter);
        $forecast = $this->forecastService->build($tenantId, 3);
        $health = $summary['financial_health'] ?? [];
        $attentionCount = (int) ($summary['attention_summary']['count'] ?? 0);
        $overdueAmount = (float) ($summary['attention_summary']['total_overdue_amount'] ?? 0);
        $monthCollected = (float) ($summary['period_collections']['current_month_collected'] ?? 0);
        $previousMonthCollected = (float) ($summary['kpis']['previous_month_collected'] ?? $summary['period_collections']['previous_month_collected'] ?? 0);
        $outstanding = (float) ($summary['totals']['pending_dues'] ?? 0);
        $families = $summary['families'] ?? [];
        $activeFamilies = (int) ($families['active'] ?? 0);
        $participatingFamilies = (int) ($families['participating_last_90_days'] ?? 0);
        $participationRate = (float) ($families['participation_rate'] ?? 0);
        $healthScores = $summary['health_scores'] ?? [];
        $overdueRatioPct = $outstanding > 0 ? min(100, round(($overdueAmount / $outstanding) * 100, 1)) : 0.0;
        $projectSummaries = array_values($summary['active_project_summaries'] ?? []);
        $projectCount = count($projectSummaries);
        $projectApplicable = $projectCount > 0;
        $growthAnalysis = $this->executiveMetrics->collectionGrowthAnalysis($tenantId, $range, $bccFilter, $projectFilter);
        $collectionGrowthPct = (float) $growthAnalysis['growth_pct'];
        $growthHealthScore = (float) $growthAnalysis['growth_health_score'];
        $factorScores = $this->executiveMetrics->healthScoreFactorDisplay(
            $participationRate,
            $overdueRatioPct,
            (float) (collect($projectSummaries)->avg('funding_percentage') ?? 0),
            $growthHealthScore,
            $projectApplicable
        );
        $weightBreakdown = collect($this->executiveMetrics->stewardshipHealthWeightBreakdown($projectApplicable))
            ->keyBy('key');
        $remainingCollectable = $this->executiveMetrics->remainingCollectableRecordAmount($tenantId, $range?->asOf, $bccFilter, $projectFilter);
        $residualRemaining = $this->executiveMetrics->remainingCollectableResidual($outstanding, $overdueAmount);
        $dueSchedule = $this->executiveMetrics->dueSchedulePartition($tenantId, $range, $bccFilter, $projectFilter);
        $collectionSnapshot = $this->executiveMetrics->collectionSnapshot($tenantId, $outstanding, $range, $bccFilter, $projectFilter);
        $healthStory = $this->buildHealthStory(
            $tenantId,
            $monthCollected,
            $collectionGrowthPct,
            $participationRate,
            $overdueAmount,
            $attentionCount,
            $remainingCollectable,
            $dueSchedule,
            $projectSummaries
        );

        $highlights = [
            sprintf('Stewardship health is %s (%s/100).', strtolower($health['label'] ?? 'calculating'), $health['score'] ?? 0),
            sprintf(
                '%s the parish collected %s.',
                $range !== null ? 'In the selected period' : 'This month',
                ChurchMoneyFormatter::formatForTenant($tenantId, $monthCollected)
            ),
        ];
        if ($attentionCount > 0 && $overdueAmount > 0) {
            $highlights[] = sprintf(
                '%s families have %s overdue.',
                $attentionCount,
                ChurchMoneyFormatter::formatForTenant($tenantId, $overdueAmount)
            );
        } elseif ($outstanding > 0) {
            $highlights[] = sprintf(
                '%s in collectable outstanding remains.',
                ChurchMoneyFormatter::formatForTenant($tenantId, $outstanding)
            );
        } else {
            $highlights[] = 'Mandatory contribution balances are clear.';
        }

        $actions = [];
        if ($attentionCount > 0) {
            $actions[] = 'Review families requiring attention on the Financial Dashboard.';
        }
        if ($outstanding > 0) {
            $actions[] = 'Use Quick Collect during services to reduce outstanding balances.';
        }
        if (($forecast['signals']['collection_growth_pct'] ?? 0) < 0) {
            $actions[] = 'Collections are pacing below recent months — consider parish-wide reminders.';
        }
        if (empty($actions)) {
            $actions[] = 'Stewardship is on track. Share gratitude updates with participating families.';
        }

        return [
            'title' => 'Executive Stewardship Summary',
            'narrative' => $health['summary'] ?? 'Parish financial summary generated for leadership review.',
            'highlights' => $highlights,
            'recommended_actions' => $actions,
            'metrics' => [
                'health_score' => $health['score'] ?? null,
                'health_status' => $health['status'] ?? null,
                'current_month_collected' => $monthCollected,
                'pending_dues' => $outstanding,
                'participation_rate' => $participationRate,
                'forecast_projection' => $forecast['signals']['current_month_projection'] ?? 0,
                'overdue_family_count' => $attentionCount,
                'overdue_amount' => $overdueAmount,
                'previous_month_collected' => $previousMonthCollected,
                'collection_growth_pct' => $collectionGrowthPct,
                'active_families' => $activeFamilies,
                'participating_families' => $participatingFamilies,
            ],
            'forecast_narrative' => $forecast['narrative'] ?? null,
            'applied_range' => $range?->appliedRangePayload(),
            'metric_basis' => $range?->metricBasisPayload(),
            'visuals' => [
                'health' => [
                    'score' => $health['score'] ?? 0,
                    'label' => $health['label'] ?? '',
                    'status' => $health['status'] ?? 'attention',
                    'story' => $healthStory,
                    'factors' => $this->buildStewardshipHealthFactors(
                        $factorScores,
                        $weightBreakdown,
                        $projectApplicable,
                        $growthAnalysis
                    ),
                    'related_metrics' => [
                        [
                            'key' => 'collection_performance',
                            'label' => 'Collection performance (not in the 100-point score)',
                            'score' => (float) ($healthScores['collection_performance']['score'] ?? 0),
                        ],
                    ],
                ],
                'collections' => [
                    'current_month_collected' => $monthCollected,
                    'previous_month_collected' => $this->executiveMetrics->comparablePreviousMonthCollected($tenantId, $range, $bccFilter, $projectFilter),
                    'previous_month_full_collected' => $previousMonthCollected,
                    'collection_growth_pct' => $collectionGrowthPct,
                    'period_label' => $range !== null && $range->preset !== DashboardDateRange::PRESET_THIS_MONTH
                        ? 'Selected period'
                        : 'This month',
                    'comparison_label' => $range !== null && $range->comparisonMode === DashboardDateRange::COMPARISON_EQUAL_LENGTH_PRIOR
                        ? 'Previous period'
                        : 'Same days last month',
                ],
                'collection_snapshot' => $collectionSnapshot,
                'outstanding' => [
                    'pending_dues' => $outstanding,
                    'overdue_amount' => $dueSchedule['overdue_amount'],
                    'remaining_collectable' => $remainingCollectable,
                    'remaining_collectable_residual' => $residualRemaining,
                    'overdue_family_count' => $dueSchedule['overdue_family_count'],
                    'next_14_days_amount' => $dueSchedule['next_14_days_amount'],
                    'next_14_days_family_count' => $dueSchedule['next_14_days_family_count'],
                    'later_remaining_amount' => $dueSchedule['later_remaining_amount'],
                    'later_remaining_family_count' => $dueSchedule['later_remaining_family_count'],
                ],
                'projects' => $projectCount > 0 ? $projectSummaries : null,
                'expenses_vs_collections' => $this->buildExpensesVsCollectionsVisual($tenantId, $monthCollected),
                'participation' => [
                    'active_families' => $activeFamilies,
                    'participating_families' => $participatingFamilies,
                    'participation_rate' => $participationRate,
                    'window_days' => $range !== null
                        ? $range->inclusiveCollectionDays()
                        : 90,
                    'window_start' => $range?->dateFrom,
                    'window_end' => $range?->collectionEnd,
                ],
                'collection_trend' => array_values($summary['collection_trend'] ?? []),
                'forecast' => [
                    'history' => $forecast['history'] ?? [],
                    'current_month_collected' => (float) ($forecast['signals']['current_month_collected'] ?? $monthCollected),
                    'current_month_projection' => (float) ($forecast['signals']['current_month_projection'] ?? 0),
                    'daily_pace' => (float) ($forecast['signals']['daily_pace'] ?? 0),
                    'collection_growth_pct' => (float) ($forecast['signals']['collection_growth_pct'] ?? $collectionGrowthPct),
                    'method' => (string) ($forecast['method'] ?? 'moving_average_with_pace'),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, float>  $factorScores
     * @param  Collection<string, array{key: string, weight_pct: float}>  $weightBreakdown
     * @param  array<string, mixed>  $growthAnalysis
     * @return array<int, array<string, mixed>>
     */
    private function buildStewardshipHealthFactors(
        array $factorScores,
        Collection $weightBreakdown,
        bool $projectApplicable,
        array $growthAnalysis
    ): array {
        $factors = [
            [
                'key' => 'family_engagement',
                'label' => 'Family engagement',
                'score' => (float) $factorScores['family_engagement'],
                'weight_pct' => (float) ($weightBreakdown['family_engagement']['weight_pct'] ?? 35),
                'not_applicable' => false,
            ],
            [
                'key' => 'overdue_health',
                'label' => 'Overdue balance health',
                'score' => (float) $factorScores['overdue_health'],
                'weight_pct' => (float) ($weightBreakdown['overdue_health']['weight_pct'] ?? 30),
                'not_applicable' => false,
            ],
            [
                'key' => 'growth_health',
                'label' => 'Collection growth health',
                'score' => (float) $factorScores['growth_health'],
                'weight_pct' => (float) ($weightBreakdown['growth_health']['weight_pct'] ?? 15),
                'not_applicable' => false,
                'comparison_available' => (bool) ($growthAnalysis['comparison_available'] ?? false),
            ],
        ];

        if ($projectApplicable) {
            array_splice($factors, 2, 0, [[
                'key' => 'project_funding',
                'label' => 'Project funding',
                'score' => (float) $factorScores['project_funding'],
                'weight_pct' => (float) ($weightBreakdown['project_funding']['weight_pct'] ?? 20),
                'not_applicable' => false,
            ]]);
        } else {
            array_splice($factors, 2, 0, [[
                'key' => 'project_funding',
                'label' => 'Project funding (not applicable)',
                'score' => null,
                'weight_pct' => 0.0,
                'not_applicable' => true,
            ]]);
        }

        return $factors;
    }

    /**
     * @param  array<string, float|int>  $dueSchedule
     * @param  array<int, array<string, mixed>>  $projectSummaries
     * @return array<string, mixed>
     */
    private function buildHealthStory(
        int $tenantId,
        float $monthCollected,
        float $collectionGrowthPct,
        float $participationRate,
        float $overdueAmount,
        int $overdueFamilyCount,
        float $remainingCollectable,
        array $dueSchedule,
        array $projectSummaries
    ): array {
        $performing = [];
        if ($monthCollected > 0) {
            $performing[] = [
                'key' => 'collected',
                'label' => 'Collected this month',
                'value' => $monthCollected,
                'value_kind' => 'money',
            ];
        }
        $performing[] = [
            'key' => 'growth',
            'label' => 'Growth vs same days last month',
            'value' => $collectionGrowthPct,
            'value_kind' => 'percent',
        ];
        if ($participationRate > 0) {
            $performing[] = [
                'key' => 'participation',
                'label' => 'Family participation (90 days)',
                'value' => $participationRate,
                'value_kind' => 'percent',
            ];
        }

        $attention = [];
        if ($overdueAmount > 0) {
            $attention[] = [
                'key' => 'overdue_amount',
                'label' => 'Overdue',
                'value' => $overdueAmount,
                'value_kind' => 'money',
            ];
        }
        if ($overdueFamilyCount > 0) {
            $attention[] = [
                'key' => 'overdue_families',
                'label' => 'Families overdue',
                'value' => $overdueFamilyCount,
                'value_kind' => 'count',
            ];
        }

        $pending = [];
        if ($remainingCollectable > 0) {
            $pending[] = [
                'key' => 'remaining_collectable',
                'label' => 'Remaining collectable (not overdue)',
                'value' => $remainingCollectable,
                'value_kind' => 'money',
            ];
        }
        $next14 = (float) ($dueSchedule['next_14_days_amount'] ?? 0);
        if ($next14 > 0) {
            $pending[] = [
                'key' => 'next_14_days',
                'label' => 'Due in the next 14 days',
                'value' => $next14,
                'value_kind' => 'money',
            ];
        }

        $opportunity = [];
        foreach ($projectSummaries as $project) {
            $pct = (float) ($project['funding_percentage'] ?? 0);
            if ($pct >= 80) {
                $opportunity[] = [
                    'key' => 'project_near_goal',
                    'label' => sprintf('%s is %.1f%% funded', $project['name'] ?? 'Project', $pct),
                    'value' => $pct,
                    'value_kind' => 'percent',
                ];
                break;
            }
        }
        if ($opportunity === [] && (int) ($dueSchedule['next_14_days_family_count'] ?? 0) > 0) {
            $opportunity[] = [
                'key' => 'upcoming_dues',
                'label' => sprintf(
                    '%d families have dues due in the next 14 days',
                    (int) $dueSchedule['next_14_days_family_count']
                ),
                'value' => (int) $dueSchedule['next_14_days_family_count'],
                'value_kind' => 'count',
            ];
        }

        return [
            'performing' => $performing,
            'attention' => $attention,
            'pending' => $pending,
            'opportunity' => $opportunity,
        ];
    }

    /**
     * @return array<string, float>|null
     */
    private function buildExpensesVsCollectionsVisual(int $tenantId, float $monthCollected): ?array
    {
        $businessDate = DonationBusinessDate::today($tenantId);
        $fyBounds = DonationBusinessDate::currentFinancialYearBounds($tenantId, $businessDate);
        if (! $this->parishExpenseService->hasAnySince($tenantId, $fyBounds['start'])) {
            return null;
        }

        $monthStart = DonationBusinessDate::monthStart($tenantId);
        $monthEnd = DonationBusinessDate::monthEnd($tenantId);
        $effectiveEnd = $monthEnd > $businessDate ? $businessDate : $monthEnd;

        return [
            'month_collected' => $monthCollected,
            'month_expenses' => $this->parishExpenseService->monthTotal($tenantId, $monthStart, $effectiveEnd),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildParishComparisonReport(int $tenantId): array
    {
        $rollup = $this->rollupService->build($tenantId);

        if (! ($rollup['available'] ?? false)) {
            return [
                'available' => false,
                'message' => $rollup['message'] ?? 'Parish comparison requires child parishes in the tenant hierarchy.',
            ];
        }

        $parishes = collect($rollup['parishes'] ?? []);
        $sorted = $parishes->sortByDesc('metrics.total_collected')->values();
        $top = $sorted->first();
        $needsAttention = $parishes->sortBy('health_score')->first();

        $highlights = [
            sprintf(
                'Diocese health is %s (%s/100) across %s parishes.',
                strtolower($rollup['financial_health']['label'] ?? 'calculating'),
                $rollup['financial_health']['score'] ?? 0,
                $rollup['scope']['parish_count'] ?? 0
            ),
            ($rollup['money_comparable'] ?? true)
                ? sprintf(
                    'Consolidated collections total %s with %s outstanding.',
                    ChurchMoneyFormatter::formatForTenant($tenantId, (float) ($rollup['consolidated']['total_collected'] ?? 0)),
                    ChurchMoneyFormatter::formatForTenant($tenantId, (float) ($rollup['consolidated']['pending_dues'] ?? 0))
                )
                : 'Parishes use different currencies — compare each parish individually below.',
        ];

        if ($top) {
            $highlights[] = sprintf(
                '%s leads collections at %s this period.',
                $top['name'] ?? 'Top parish',
                ChurchMoneyFormatter::formatForTenant((int) ($top['tenant_id'] ?? $tenantId), (float) ($top['metrics']['total_collected'] ?? 0))
            );
        }

        if ($needsAttention) {
            $highlights[] = sprintf(
                '%s needs the most attention with a health score of %s/100.',
                $needsAttention['name'] ?? 'A parish',
                $needsAttention['health_score'] ?? 0
            );
        }

        return [
            'available' => true,
            'title' => 'Parish Comparison Report',
            'narrative' => sprintf(
                '%s oversees %s parishes. Use this comparison to celebrate momentum and support parishes that need follow-up.',
                $rollup['root']['name'] ?? 'Diocese',
                $rollup['scope']['parish_count'] ?? 0
            ),
            'highlights' => $highlights,
            'money_comparable' => $rollup['money_comparable'] ?? true,
            'consolidated' => $rollup['consolidated'] ?? [],
            'financial_health' => $rollup['financial_health'] ?? null,
            'parishes' => $sorted->map(fn (array $row) => [
                'tenant_id' => $row['tenant_id'] ?? null,
                'name' => $row['name'] ?? 'Parish',
                'health_score' => $row['health_score'] ?? 0,
                'health_label' => $row['health_label'] ?? '',
                'health_status' => $row['health_status'] ?? '',
                'participation_rate' => $row['participation_rate'] ?? 0,
                'total_collected' => $row['metrics']['total_collected'] ?? 0,
                'pending_dues' => $row['metrics']['pending_dues'] ?? 0,
                'current_month_collected' => $row['metrics']['current_month_collected'] ?? 0,
            ])->all(),
        ];
    }

    public function processExport(string $exportId): ?DonationReportExport
    {
        return app(DonationReportExportOrchestrator::class)
            ->processExportRecord($exportId);
    }

    public function buildPaymentsCsv(int $tenantId): string
    {
        $directory = $this->ensureReportDirectory();
        $filename = sprintf('donation_payments_%d_%s.csv', $tenantId, now()->format('Ymd_His'));
        $path = $directory.'/'.$filename;

        $handle = fopen($path, 'w');
        $currencyCode = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId) ?? '';
        fputcsv($handle, ['payment_number', 'payment_date', 'payer_name', 'method', 'status', 'amount', 'currency_code', 'is_anonymous', 'source_type']);

        DonationPayment::forTenant($tenantId)->orderBy('payment_date')->chunk(200, function ($rows) use ($handle): void {
            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->payment_number,
                    $row->payment_date,
                    $row->payer_name,
                    $row->method,
                    $row->status,
                    $row->amount,
                    $currencyCode,
                    $row->is_anonymous ? 'yes' : 'no',
                    $row->source_type,
                ]);
            }
        });

        fclose($handle);

        return 'reports/'.$filename;
    }

    public function buildDonationEntriesCsv(int $tenantId): string
    {
        $directory = $this->ensureReportDirectory();
        $filename = sprintf('donation_entries_%d_%s.csv', $tenantId, now()->format('Ymd_His'));
        $path = $directory.'/'.$filename;

        $handle = fopen($path, 'w');
        fputcsv($handle, [
            'title',
            'category',
            'donor',
            'family_id',
            'financial_year',
            'status',
            'pledged_amount',
            'collected_amount',
            'received_at',
            'is_anonymous',
        ]);

        Donation::forTenant($tenantId)
            ->with(['category:id,name', 'donor:id,name'])
            ->orderByDesc('received_at')
            ->chunk(200, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->title,
                        $row->category?->name,
                        $row->is_anonymous ? 'Anonymous' : $row->donor?->name,
                        $row->family_id,
                        $row->financial_year,
                        $row->status,
                        $row->pledged_amount,
                        $row->collected_amount,
                        $row->received_at,
                        $row->is_anonymous ? 'yes' : 'no',
                    ]);
                }
            });

        fclose($handle);

        return 'reports/'.$filename;
    }

    private function ensureReportDirectory(): string
    {
        $directory = storage_path('app/reports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }
}
