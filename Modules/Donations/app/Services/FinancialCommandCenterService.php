<?php

namespace Modules\Donations\Services;

use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Family\Models\Family;

class FinancialCommandCenterService
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly DashboardInsightsService $insightsService,
        private readonly ActionCenterService $actionCenterService,
        private readonly GeographicBreakdownService $geographicBreakdownService,
        private readonly ParishExpenseService $parishExpenseService,
        private readonly CollectionForecastService $forecastService,
        private readonly WhatsAppDeliveryService $whatsAppDeliveryService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, ?User $user = null, string $period = 'month'): array
    {
        $summary = $this->dashboardService->getSummary($tenantId);
        $currency = $summary['tenant_context']['currency_code'] ?? 'INR';
        $periodCollections = $summary['period_collections'] ?? [];
        $totals = $summary['totals'] ?? [];
        $families = $summary['families'] ?? [];
        $kpis = $summary['kpis'] ?? [];

        $monthExpenses = $this->parishExpenseService->monthTotal($tenantId);
        $annualExpenses = $this->parishExpenseService->yearTotal(
            $tenantId,
            $this->resolveFyStartFromSummary($summary)
        );
        $monthCollected = (float) ($periodCollections['current_month_collected'] ?? 0);
        $annualCollected = (float) ($periodCollections['annual_collected'] ?? 0);
        $expenseRatio = $monthCollected > 0 ? round(($monthExpenses / $monthCollected) * 100, 1) : 0.0;
        $netPosition = round($annualCollected - $annualExpenses - (float) ($totals['pending_dues'] ?? 0), 2);

        $todayPayments = $this->buildTodayCollections($tenantId);
        $forecast = $this->forecastService->build($tenantId, 3);
        $whatsapp = $this->whatsAppDeliveryService->summary($tenantId);

        $healthIndex = $summary['financial_health'] ?? [];
        $healthIndex['ai_summary'] = $this->buildHealthAiSummary($healthIndex, $summary);

        $trend = $this->filterTrendByPeriod($summary['collection_trend'] ?? [], $period, $summary);
        $collectionChart = $this->filterChartByPeriod($summary['collection_performance_chart'] ?? null, $trend);

        return [
            'meta' => [
                'church_name' => $summary['tenant_context']['name'] ?? 'Parish',
                'financial_year' => $periodCollections['financial_year'] ?? null,
                'currency_code' => $currency,
                'period' => $period,
                'last_synced_at' => now()->toIso8601String(),
                'operator_name' => $user?->name,
                'operator_role' => $user?->role_name ?? $user?->role?->name,
            ],
            'health_index' => $healthIndex,
            'collection_health' => $this->buildCollectionHealth($summary, $forecast),
            'health_scores' => $summary['health_scores'] ?? [],
            'executive_cards' => $this->buildExecutiveCards($summary, $monthExpenses, $expenseRatio, $netPosition),
            'action_center' => $this->actionCenterService->buildQueues($tenantId),
            'analytics' => [
                'collection_trend' => $trend,
                'collection_performance_chart' => $collectionChart,
                'family_engagement' => [
                    'active_contributors' => (int) ($families['participating_last_90_days'] ?? 0),
                    'inactive_families' => max(0, (int) ($families['active'] ?? 0) - (int) ($families['participating_last_90_days'] ?? 0)),
                    'participation_rate' => (float) ($families['participation_rate'] ?? 0),
                    'contributing_families_delta' => (int) ($families['contributing_families_delta'] ?? 0),
                ],
                'project_funding' => $summary['active_project_summaries'] ?? [],
                'geographic' => $this->geographicBreakdownService->build($tenantId),
            ],
            'intelligence' => $this->buildIntelligenceStream($summary, $tenantId),
            'ai_advisor' => [
                'insights' => $summary['proactive_insights'] ?? [],
                'forecast_narrative' => $forecast['narrative'] ?? null,
                'narratives' => $this->buildAdvisorNarratives($summary, $forecast),
                'recommended_actions' => $this->buildAdvisorActions($summary, $forecast),
            ],
            'contribution_intelligence' => [
                'top_contributors' => $this->buildTopContributors($tenantId),
                'recent_contributors' => $this->buildRecentContributors($tenantId),
                'largest_gifts' => $this->buildLargestGifts($tenantId),
                'returning_families' => $this->buildReturningFamilies($tenantId),
                'giving_streaks' => $this->buildGivingStreaks($tenantId),
            ],
            'collections_command' => [
                'today_count' => $todayPayments['count'],
                'today_collected' => $todayPayments['total'],
                'families_processed_today' => $todayPayments['families'],
                'target' => $this->buildCollectionTarget($monthCollected),
                'completion_pct' => $this->buildCollectionTarget($monthCollected) > 0
                    ? round(min(100, ($todayPayments['total'] / $this->buildCollectionTarget($monthCollected)) * 100), 1)
                    : 0,
            ],
            'projects_command' => $this->buildProjectsCommand($tenantId),
            'communication_center' => [
                'whatsapp_queued' => (int) ($whatsapp['queued'] ?? 0),
                'whatsapp_sent' => (int) ($whatsapp['sent'] ?? 0),
                'whatsapp_failed' => (int) ($whatsapp['failed'] ?? 0),
                'families_awaiting_follow_up' => (int) ($summary['attention_summary']['count'] ?? 0),
                'recent_messages' => $whatsapp['recent'] ?? [],
            ],
            'expense_summary' => [
                'month_total' => $monthExpenses,
                'annual_total' => $annualExpenses,
                'expense_ratio_pct' => $expenseRatio,
                'recent' => $this->parishExpenseService->recent($tenantId, 5),
            ],
            'kpis' => $kpis,
            'totals' => $totals,
            'period_collections' => $periodCollections,
            'tenant_context' => $summary['tenant_context'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $forecast
     * @return array<string, mixed>
     */
    private function buildCollectionHealth(array $summary, array $forecast): array
    {
        $health = $summary['financial_health'] ?? [];
        $healthScores = $summary['health_scores'] ?? [];
        $kpis = $summary['kpis'] ?? [];
        $families = $summary['families'] ?? [];
        $attention = $summary['attention_summary'] ?? [];
        $projects = $summary['active_project_summaries'] ?? [];
        $totals = $summary['totals'] ?? [];
        $currency = $summary['tenant_context']['currency_code'] ?? 'INR';

        $score = (float) ($health['score'] ?? 0);
        $status = (string) ($health['status'] ?? 'attention');
        $growth = (float) ($kpis['collection_growth_pct'] ?? 0);
        $planCompliance = (float) ($kpis['plan_compliance_pct'] ?? 0);
        $participation = (float) ($families['participation_rate'] ?? 0);
        $overdueCount = (int) ($attention['count'] ?? 0);
        $overdueAmount = (float) ($attention['total_overdue_amount'] ?? 0);
        $inactive = max(0, (int) ($families['active'] ?? 0) - (int) ($families['participating_last_90_days'] ?? 0));
        $outstanding = (float) ($totals['pending_dues'] ?? 0);
        $overdueRatio = $outstanding > 0 ? min(100, round(($overdueAmount / $outstanding) * 100, 1)) : 0.0;
        $overdueHealth = min(100, max(0, 100 - $overdueRatio));
        $avgProject = count($projects) > 0
            ? round(collect($projects)->avg('funding_percentage'), 1)
            : 0.0;
        $growthHealth = min(100, max(0, 50 + ($growth / 2)));
        $collectionPerformance = (float) ($healthScores['collection_performance']['score'] ?? $planCompliance);

        $statusLabel = match ($status) {
            'healthy' => 'On Track',
            'attention' => 'Moderate Risk',
            default => 'High Risk',
        };

        $issues = [];
        if ($overdueCount > 0) {
            $issues[] = [
                'severity' => $overdueCount >= 10 ? 'critical' : 'warning',
                'message' => sprintf('%d %s overdue contributions', $overdueCount, $overdueCount === 1 ? 'family has' : 'families have'),
                'cta_route' => '/donations/dues',
            ];
        }

        $laggingProject = collect($projects)->first(fn (array $project) => (float) ($project['funding_percentage'] ?? 0) < 50);
        if ($laggingProject) {
            $gap = max(0, (float) ($laggingProject['target_amount'] ?? 0) - (float) ($laggingProject['collected'] ?? 0));
            $issues[] = [
                'severity' => 'warning',
                'message' => sprintf('%s is below target', $laggingProject['name'] ?? 'A project'),
                'detail' => $gap > 0 ? sprintf('Funding gap of %s %s', $currency, number_format($gap, 2)) : null,
                'cta_route' => '/donations/projects',
            ];
        }

        $delta = (int) ($families['contributing_families_delta'] ?? 0);
        if ($delta < 0) {
            $issues[] = [
                'severity' => 'warning',
                'message' => sprintf('Participation dropped by %d %s', abs($delta), abs($delta) === 1 ? 'family' : 'families'),
                'cta_route' => '/donations/collection-health',
            ];
        } elseif ($growth < 0) {
            $issues[] = [
                'severity' => 'warning',
                'message' => sprintf('Collections declined %.1f%% this month', abs($growth)),
                'cta_route' => '/donations/reports',
            ];
        }

        if ($inactive > 0) {
            $issues[] = [
                'severity' => $inactive >= 7 ? 'warning' : 'normal',
                'message' => sprintf('%d %s inactive for more than 90 days', $inactive, $inactive === 1 ? 'family is' : 'families are'),
                'cta_route' => '/donations/dues',
            ];
        }

        $primaryReason = $overdueCount > 0
            ? sprintf('%d %s require follow-up', $overdueCount, $overdueCount === 1 ? 'family' : 'families')
            : ($growth < 0 ? 'Collections declined this month' : 'Participation needs monitoring');

        $secondaryReason = null;
        if ($laggingProject) {
            $gap = max(0, (float) ($laggingProject['target_amount'] ?? 0) - (float) ($laggingProject['collected'] ?? 0));
            $secondaryReason = $gap > 0
                ? sprintf('%s behind by %s %s', $laggingProject['name'] ?? 'Project', $currency, number_format($gap, 2))
                : sprintf('%s funding below target', $laggingProject['name'] ?? 'Project');
        } elseif ($inactive > 0) {
            $secondaryReason = sprintf('%d inactive families', $inactive);
        }

        $insights = [];
        if ($growth !== 0.0) {
            $insights[] = sprintf(
                'Collection participation %s by %.1f%% compared to last month.',
                $growth >= 0 ? 'increased' : 'decreased',
                abs($growth)
            );
        }
        if ($overdueCount > 0 && $overdueAmount > 0) {
            $insights[] = sprintf(
                '%d families account for a significant share of %s %s in overdue contributions.',
                $overdueCount,
                $currency,
                number_format($overdueAmount, 2)
            );
        }
        if ($laggingProject) {
            $gap = max(0, (float) ($laggingProject['target_amount'] ?? 0) - (float) ($laggingProject['collected'] ?? 0));
            if ($gap > 0) {
                $insights[] = sprintf(
                    '%s collections are projected to miss target by %s %s.',
                    $laggingProject['name'] ?? 'A project',
                    $currency,
                    number_format($gap, 2)
                );
            }
        }
        if (!empty($forecast['narrative'])) {
            $insights[] = (string) $forecast['narrative'];
        }
        if ($overdueCount > 0) {
            $insights[] = 'Sending reminders this week could improve collection completion across overdue families.';
        }

        return [
            'score' => $score,
            'max_score' => 100,
            'label' => (string) ($health['label'] ?? 'Calculating'),
            'status' => $status,
            'status_label' => $statusLabel,
            'summary' => (string) ($health['summary'] ?? ''),
            'trend_pct' => $growth,
            'trend_direction' => $growth >= 0 ? 'up' : 'down',
            'issue_count' => count($issues),
            'primary_reason' => $primaryReason,
            'secondary_reason' => $secondaryReason,
            'action_label' => count($issues) > 0 ? 'Review Issues' : 'Open Health Center',
            'action_route' => '/donations/collection-health',
            'factors' => [
                $this->collectionHealthFactor('completion', 'Collection Completion Rate', $collectionPerformance, 35, $growth),
                $this->collectionHealthFactor('participation', 'Family Participation Rate', $participation, 35, (float) $delta),
                $this->collectionHealthFactor('overdue', 'Overdue Contributions', $overdueHealth, 30, null),
                $this->collectionHealthFactor('growth', 'Contribution Growth Trend', $growthHealth, 15, $growth),
                $this->collectionHealthFactor('projects', 'Project Funding Progress', $avgProject, 20, null),
            ],
            'issues' => $issues,
            'recommended_actions' => [
                ['id' => 'overdue', 'label' => 'View Overdue Families', 'route' => '/donations/dues'],
                ['id' => 'reminders', 'label' => 'Send Follow-Up Reminders', 'route' => '/donations/notifications'],
                ['id' => 'performance', 'label' => 'Review Collection Performance', 'route' => '/donations/collection-health'],
                ['id' => 'projects', 'label' => 'Analyze Funding Projects', 'route' => '/donations/projects'],
                ['id' => 'outreach', 'label' => 'Generate Outreach Campaign', 'route' => '/donations/notifications'],
                ['id' => 'export', 'label' => 'Export Follow-Up List', 'route' => '/donations/reports'],
            ],
            'insights' => array_slice($insights, 0, 4),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionHealthFactor(
        string $key,
        string $label,
        float $score,
        int $weightPct,
        ?float $trendPct
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'score' => round($score, 1),
            'weight_pct' => $weightPct,
            'status' => $this->collectionHealthFactorStatus($score),
            'trend_pct' => $trendPct,
            'trend_direction' => $trendPct === null ? null : ($trendPct >= 0 ? 'up' : 'down'),
        ];
    }

    private function collectionHealthFactorStatus(float $score): string
    {
        return match (true) {
            $score >= 80 => 'healthy',
            $score >= 50 => 'attention',
            default => 'risk',
        };
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function buildExecutiveCards(array $summary, float $monthExpenses, float $expenseRatio, float $netPosition): array
    {
        $period = $summary['period_collections'] ?? [];
        $totals = $summary['totals'] ?? [];
        $families = $summary['families'] ?? [];
        $kpis = $summary['kpis'] ?? [];
        $growth = (float) ($kpis['collection_growth_pct'] ?? 0);
        $projects = $summary['active_project_summaries'] ?? [];
        $avgFunding = count($projects) > 0
            ? round(collect($projects)->avg('funding_percentage'), 1)
            : 0;

        $currentMonth = (float) ($period['current_month_collected'] ?? 0);
        $previousMonth = (float) ($period['previous_month_collected'] ?? 0);
        $annual = (float) ($period['annual_collected'] ?? 0);

        return [
            $this->executiveCard('month_collected', 'Total Contributions This Month', $currentMonth, $growth, 'Compared to last month', $growth >= 0 ? 'Collection performance is tracking period momentum.' : 'Month-over-month collections declined.'),
            $this->executiveCard('year_collected', 'Total Contributions This Year', $annual, null, 'Financial year to date', 'Year-to-date giving across all contribution types.'),
            $this->executiveCard('outstanding', 'Outstanding Dues', (float) ($totals['pending_dues'] ?? 0), null, 'Open balances', 'Outstanding mandatory and project balances awaiting collection.'),
            $this->executiveCard('efficiency', 'Collection Efficiency', (float) ($kpis['plan_compliance_pct'] ?? 0), null, 'Plan compliance %', 'Share of assessed dues collected this month.'),
            $this->executiveCard('participation', 'Family Participation Rate', (float) ($families['participation_rate'] ?? 0), (float) ($families['contributing_families_delta'] ?? 0), 'Active families (90 days)', 'Families contributing in the last 90 days.'),
            $this->executiveCard('projects', 'Project Funding Status', $avgFunding, null, 'Average funding %', 'Average progress across active parish projects.'),
            $this->executiveCard('expense_ratio', 'Expense Ratio', $expenseRatio, null, 'Disbursements vs collections', sprintf('Parish disbursements are %.1f%% of this month\'s collections.', $expenseRatio)),
            $this->executiveCard('net_position', 'Net Financial Position', $netPosition, null, 'Collections − disbursements − outstanding', 'Simplified parish financial position for leadership review.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executiveCard(
        string $key,
        string $label,
        float $value,
        ?float $trend,
        string $comparison,
        string $context
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => round($value, 2),
            'trend_pct' => $trend,
            'trend_direction' => $trend === null ? null : ($trend >= 0 ? 'up' : 'down'),
            'comparison_period' => $comparison,
            'context_message' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $health
     * @param array<string, mixed> $summary
     */
    private function buildHealthAiSummary(array $health, array $summary): string
    {
        $score = (float) ($health['score'] ?? 0);
        $label = (string) ($health['label'] ?? 'Calculating');
        $overdue = (int) ($summary['attention_summary']['count'] ?? 0);

        if ($score >= 80) {
            return "Church finances remain {$label}. Contribution growth continues while overdue balances remain manageable.";
        }
        if ($overdue > 0) {
            return "{$overdue} families need follow-up. Focus the action center on overdue collections this week.";
        }

        return 'Review collection performance and family engagement to strengthen parish financial health.';
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function buildIntelligenceStream(array $summary, int $tenantId): array
    {
        $events = [];

        foreach ($summary['recent_activity'] ?? [] as $payment) {
            $events[] = [
                'type' => 'payment',
                'title' => 'Payment received',
                'subtitle' => ($payment['family_name'] ?? $payment['payer_name'] ?? 'Contributor'),
                'amount' => (float) ($payment['amount'] ?? 0),
                'date' => $payment['date'] ?? null,
                'reference' => $payment['receipt_number'] ?? null,
            ];
        }

        foreach ($this->parishExpenseService->recent($tenantId, 5) as $expense) {
            $events[] = [
                'type' => 'expense',
                'title' => 'Disbursement recorded',
                'subtitle' => $expense['category'] ?? 'Expense',
                'amount' => (float) ($expense['amount'] ?? 0),
                'date' => $expense['expense_date'] ?? null,
                'reference' => $expense['payee'] ?? null,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return array_slice($events, 0, 20);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $forecast
     * @return array<int, string>
     */
    private function buildAdvisorActions(array $summary, array $forecast): array
    {
        $actions = [];
        foreach ($summary['proactive_insights'] ?? [] as $insight) {
            if (!empty($insight['action']['label'])) {
                $actions[] = $insight['action']['label'];
            }
        }
        if (!empty($forecast['narrative'])) {
            $actions[] = 'Forecast Cash Flow';
        }
        if ((int) ($summary['attention_summary']['count'] ?? 0) > 0) {
            $actions[] = 'Generate Outreach Plan';
        }

        return array_values(array_unique(array_slice($actions, 0, 5)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopContributors(int $tenantId): array
    {
        $start = now()->subMonths(12)->toDateString();

        return DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $start)
            ->with('family:id,family_name,family_code')
            ->get()
            ->groupBy('family_id')
            ->map(function ($payments, $familyId) {
                $first = $payments->first();

                return [
                    'family_id' => $familyId,
                    'family_name' => $first->family?->family_name,
                    'family_code' => $first->family?->family_code,
                    'total_paid' => round((float) $payments->sum('amount'), 2),
                    'payment_count' => $payments->count(),
                ];
            })
            ->sortByDesc('total_paid')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentContributors(int $tenantId): array
    {
        $start = now()->subDays(30)->toDateString();

        return DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $start)
            ->with('family:id,family_name,family_code')
            ->orderByDesc('payment_date')
            ->limit(10)
            ->get()
            ->map(fn (DonationPayment $payment) => [
                'family_id' => $payment->family_id,
                'family_name' => $payment->family?->family_name,
                'family_code' => $payment->family?->family_code,
                'amount' => (float) $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{count: int, total: float, families: int}
     */
    private function buildTodayCollections(int $tenantId): array
    {
        $today = now()->toDateString();
        $payments = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereDate('payment_date', $today)
            ->get();

        return [
            'count' => $payments->count(),
            'total' => round((float) $payments->sum('amount'), 2),
            'families' => $payments->pluck('family_id')->filter()->unique()->count(),
        ];
    }

    private function buildCollectionTarget(float $monthCollected): float
    {
        return max(round($monthCollected * 0.15, 2), 1000);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectsCommand(int $tenantId): array
    {
        return DonationProject::forTenant($tenantId)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get()
            ->map(function (DonationProject $project): array {
                $target = max((float) $project->target_amount, 1);
                $collected = (float) $project->raised_amount;
                $pct = round(min(100, ($collected / $target) * 100), 1);
                $gap = round(max(0, $target - $collected), 2);

                return [
                    'project_id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'target_amount' => round($target, 2),
                    'collected' => round($collected, 2),
                    'funding_gap' => $gap,
                    'funding_percentage' => $pct,
                    'risk_level' => $pct < 40 ? 'high' : ($pct < 70 ? 'medium' : 'low'),
                    'status' => $project->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function resolveFyStartFromSummary(array $summary): string
    {
        $fy = (string) ($summary['period_collections']['financial_year'] ?? '');
        if (str_contains($fy, '-')) {
            $startYear = (int) explode('-', $fy)[0];

            return sprintf('%d-04-01', $startYear);
        }

        return now()->startOfYear()->toDateString();
    }

    /**
     * @param array<int, array<string, mixed>> $trend
     * @param array<string, mixed> $summary
     * @return array<int, array<string, mixed>>
     */
    private function filterTrendByPeriod(array $trend, string $period, array $summary): array
    {
        if ($trend === []) {
            return [];
        }

        $period = strtolower($period);
        if ($period === 'year') {
            return $trend;
        }

        if ($period === 'fy') {
            $fyStart = $this->resolveFyStartFromSummary($summary);
            $fyKey = substr($fyStart, 0, 7);

            return array_values(array_filter($trend, fn (array $row) => ($row['period'] ?? '') >= $fyKey));
        }

        if ($period === 'quarter') {
            return array_slice($trend, -3);
        }

        return array_slice($trend, -1);
    }

    /**
     * @param array<string, mixed>|null $chart
     * @param array<int, array<string, mixed>> $trend
     * @return array<string, mixed>|null
     */
    private function filterChartByPeriod(?array $chart, array $trend): ?array
    {
        if (!$chart || $trend === []) {
            return $chart;
        }

        $periods = array_flip(array_column($trend, 'period'));
        $series = collect($chart['series'] ?? [])->map(function (array $series) use ($periods): array {
            $series['points'] = array_values(array_filter(
                $series['points'] ?? [],
                fn (array $point) => isset($periods[$point['period'] ?? ''])
            ));

            return $series;
        })->all();

        return array_merge($chart, ['series' => $series]);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $forecast
     * @return array<int, string>
     */
    private function buildAdvisorNarratives(array $summary, array $forecast): array
    {
        $narratives = [];
        $growth = (float) ($summary['kpis']['collection_growth_pct'] ?? 0);
        $monthCollected = (float) ($summary['period_collections']['current_month_collected'] ?? 0);
        $currency = $summary['tenant_context']['currency_code'] ?? 'INR';

        $narratives[] = $growth >= 0
            ? sprintf('Collections grew %.1f%% versus last month with %s %s received this month.', $growth, $currency, number_format($monthCollected, 2))
            : sprintf('Collections declined %.1f%% versus last month — review the action center priorities.', abs($growth));

        $attention = (int) ($summary['attention_summary']['count'] ?? 0);
        $pending = (float) ($summary['totals']['pending_dues'] ?? 0);
        if ($attention > 0) {
            $narratives[] = sprintf('%d families are overdue on mandatory contributions with %s %s exposed.', $attention, $currency, number_format((float) ($summary['attention_summary']['total_overdue_amount'] ?? 0), 2));
        } elseif ($pending > 0) {
            $narratives[] = sprintf('%s %s remains outstanding across the parish with no families currently overdue.', $currency, number_format($pending, 2));
        } else {
            $narratives[] = 'Mandatory contribution balances are clear — focus on project momentum and voluntary pledges.';
        }

        if (!empty($forecast['narrative'])) {
            $narratives[] = (string) $forecast['narrative'];
        }

        return array_slice($narratives, 0, 3);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLargestGifts(int $tenantId): array
    {
        $start = now()->subMonths(12)->toDateString();

        return DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $start)
            ->with('family:id,family_name,family_code')
            ->orderByDesc('amount')
            ->limit(5)
            ->get()
            ->map(fn (DonationPayment $payment) => [
                'family_id' => $payment->family_id,
                'family_name' => $payment->family?->family_name,
                'family_code' => $payment->family?->family_code,
                'amount' => round((float) $payment->amount, 2),
                'payment_date' => $payment->payment_date?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildReturningFamilies(int $tenantId): array
    {
        $recentStart = now()->subDays(30)->toDateString();
        $gapStart = now()->subDays(120)->toDateString();
        $gapEnd = now()->subDays(31)->toDateString();

        $recentFamilies = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $recentStart)
            ->pluck('family_id')
            ->unique();

        $inactiveDuringGap = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereBetween('payment_date', [$gapStart, $gapEnd])
            ->pluck('family_id')
            ->unique();

        $returningIds = $recentFamilies->diff($inactiveDuringGap)->take(10);

        return Family::query()
            ->whereIn('id', $returningIds)
            ->get(['id', 'family_name', 'family_code'])
            ->map(fn ($family) => [
                'family_id' => $family->id,
                'family_name' => $family->family_name,
                'family_code' => $family->family_code,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildGivingStreaks(int $tenantId): array
    {
        $start = now()->subMonths(11)->startOfMonth();
        $payments = DonationPayment::forTenant($tenantId)
            ->where('status', 'succeeded')
            ->whereNotNull('family_id')
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->get(['family_id', 'payment_date', 'amount']);

        $monthsByFamily = [];
        foreach ($payments as $payment) {
            $month = $payment->payment_date?->format('Y-m');
            if (!$month) {
                continue;
            }
            $monthsByFamily[$payment->family_id][$month] = true;
        }

        $streaks = [];
        foreach ($monthsByFamily as $familyId => $months) {
            $sorted = array_keys($months);
            sort($sorted);
            $streak = 1;
            $maxStreak = 1;
            for ($i = 1; $i < count($sorted); $i++) {
                $prev = \Carbon\Carbon::createFromFormat('Y-m', $sorted[$i - 1])->startOfMonth();
                $curr = \Carbon\Carbon::createFromFormat('Y-m', $sorted[$i])->startOfMonth();
                if ($prev->copy()->addMonth()->format('Y-m') === $curr->format('Y-m')) {
                    $streak++;
                    $maxStreak = max($maxStreak, $streak);
                } else {
                    $streak = 1;
                }
            }
            if ($maxStreak >= 3) {
                $streaks[] = ['family_id' => $familyId, 'months' => $maxStreak];
            }
        }

        usort($streaks, fn (array $a, array $b) => $b['months'] <=> $a['months']);
        $topIds = collect($streaks)->take(10)->pluck('family_id');
        $families = Family::query()->whereIn('id', $topIds)->get(['id', 'family_name', 'family_code'])->keyBy('id');

        return collect($streaks)->take(10)->map(function (array $row) use ($families): array {
            $family = $families->get($row['family_id']);

            return [
                'family_id' => $row['family_id'],
                'family_name' => $family?->family_name,
                'family_code' => $family?->family_code,
                'consecutive_months' => $row['months'],
            ];
        })->values()->all();
    }
}
