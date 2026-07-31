<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Support\ContributionBalance;
use Modules\Tenants\Services\TenantHierarchyService;

class FinancialAiQueryService
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly TenantHierarchyService $tenantHierarchyService,
        private readonly FinancialIntentVectorMatcher $intentMatcher,
        private readonly CollectionForecastService $forecastService,
        private readonly WhatsAppOutreachService $whatsAppOutreachService,
        private readonly FinancialLlmAdapterService $llmAdapter
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function process(int $tenantId, string $prompt, ?string $role = null): array
    {
        $intent = $this->intentMatcher->match($prompt);
        $tenantIds = [$tenantId];

        if ($this->shouldUseRollup($intent, $role)) {
            $tenantIds = $this->tenantHierarchyService->descendantIds($tenantId, true);
        }

        $response = match ($intent['type']) {
            'overdue_families' => $this->overdueFamiliesResponse($tenantIds, $intent),
            'project_lagging' => $this->projectLaggingResponse($tenantIds, $intent),
            'financial_summary' => $this->summaryResponse($tenantId),
            'collection_forecast' => $this->forecastResponse($tenantId, $intent),
            'top_contributors' => $this->topContributorsResponse($tenantId, $intent),
            'compare_collections' => $this->compareCollectionsResponse($tenantId),
            'whatsapp_outreach' => $this->whatsAppOutreachResponse($tenantId),
            default => $this->helpResponse(),
        };

        $response['match_score'] = $intent['score'] ?? 0;
        $response['engine'] = 'semantic_v2';

        return $this->llmAdapter->enrich($tenantId, $prompt, $response);
    }

    /**
     * @param array<int> $tenantIds
     * @param array{type: string, score?: float, params: array<string, mixed>} $intent
     * @return array<string, mixed>
     */
    private function overdueFamiliesResponse(array $tenantIds, array $intent): array
    {
        $today = now()->toDateString();
        $dues = ContributionDue::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $today)
            ->with(['family:id,family_name,family_code,tenant_id', 'plan:id,name'])
            ->get();

        $rows = $dues->groupBy('family_id')->map(function ($familyDues) {
            $first = $familyDues->first();

            return [
                'family_id' => $first->family_id,
                'family_name' => $first->family?->family_name,
                'family_code' => $first->family?->family_code,
                'overdue_amount' => round((float) $familyDues->sum(fn ($due) => ContributionBalance::outstandingForDue($due)), 2),
                'overdue_count' => $familyDues->count(),
                'focus_area' => $first->plan?->name,
            ];
        })->sortByDesc('overdue_amount')->values()->take(15)->all();

        $totalExposure = round(collect($rows)->sum('overdue_amount'), 2);

        return [
            'intent' => 'overdue_families',
            'answer' => count($rows) > 0
                ? sprintf('Found %d families with overdue mandatory contributions totaling %s.', count($rows), number_format($totalExposure, 2))
                : 'No overdue mandatory contributions were found in your scope.',
            'metrics' => [
                'family_count' => count($rows),
                'total_exposure' => $totalExposure,
            ],
            'families' => $rows,
            'recommended_actions' => count($rows) > 0
                ? [
                    'Review the attention list on the Financial Dashboard.',
                    'Open each family profile and use Quick Collect for follow-up payments.',
                    'Use WhatsApp outreach for families with valid phone numbers.',
                ]
                : ['Continue monitoring monthly collection trends.'],
        ];
    }

    /**
     * @param array<int> $tenantIds
     * @param array{type: string, score?: float, params: array<string, mixed>} $intent
     * @return array<string, mixed>
     */
    private function projectLaggingResponse(array $tenantIds, array $intent): array
    {
        $projects = DonationProject::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('status', 'active')
            ->get(['id', 'name', 'code', 'target_amount', 'raised_amount']);

        $lagging = $projects->filter(function (DonationProject $project): bool {
            $target = max((float) $project->target_amount, 1);
            $pct = ((float) $project->raised_amount / $target) * 100;

            return $pct < 50;
        })->map(fn (DonationProject $project) => [
            'project_id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'target_amount' => round((float) $project->target_amount, 2),
            'collected' => round((float) $project->raised_amount, 2),
            'funding_percentage' => round(min(100, ((float) $project->raised_amount / max((float) $project->target_amount, 1)) * 100), 1),
        ])->values()->all();

        return [
            'intent' => 'project_lagging',
            'answer' => count($lagging) > 0
                ? sprintf('%d active projects are below 50%% funding progress.', count($lagging))
                : 'All active projects are above the early-stage funding threshold.',
            'projects' => $lagging,
            'recommended_actions' => [
                'Review project dashboards and installment schedules.',
                'Use Family 360° profiles to identify families with project balances.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryResponse(int $tenantId): array
    {
        $summary = $this->dashboardService->getSummary($tenantId);

        return [
            'intent' => 'financial_summary',
            'answer' => $summary['financial_health']['summary'] ?? 'Financial summary generated for your parish.',
            'health' => $summary['financial_health'] ?? null,
            'totals' => $summary['totals'] ?? null,
            'period_collections' => $summary['period_collections'] ?? null,
            'recommended_actions' => [
                'Open the Financial Dashboard for trend and attention details.',
                'Use Express Quick Collect during weekend services.',
            ],
        ];
    }

    /**
     * @param array{type: string, score?: float, params: array<string, mixed>} $intent
     * @return array<string, mixed>
     */
    private function forecastResponse(int $tenantId, array $intent): array
    {
        $horizon = (int) ($intent['params']['months'] ?? 3);
        $forecast = $this->forecastService->build($tenantId, $horizon);

        return [
            'intent' => 'collection_forecast',
            'answer' => $forecast['narrative'],
            'forecast' => $forecast,
            'recommended_actions' => [
                'Review the forecast panel on the Financial Dashboard.',
                'Compare projected collections against active project targets.',
            ],
        ];
    }

    /**
     * @param array{type: string, score?: float, params: array<string, mixed>} $intent
     * @return array<string, mixed>
     */
    private function topContributorsResponse(int $tenantId, array $intent): array
    {
        $limit = max(3, min(15, (int) ($intent['params']['limit'] ?? 10)));
        $start = now()->subMonths(11)->startOfMonth()->toDateString();

        $rows = DonationPayment::forTenant($tenantId)
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
            ->values()
            ->take($limit)
            ->all();

        return [
            'intent' => 'top_contributors',
            'answer' => count($rows) > 0
                ? sprintf('Top %d contributing families over the last 12 months are listed below.', count($rows))
                : 'No family contribution payments were found in the last 12 months.',
            'families' => $rows,
            'recommended_actions' => [
                'Recognize consistent contributors in parish communications.',
                'Review Family 360° profiles for engagement opportunities.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compareCollectionsResponse(int $tenantId): array
    {
        $summary = $this->dashboardService->getSummary($tenantId);
        $period = $summary['period_collections'] ?? [];
        $current = (float) ($period['current_month_collected'] ?? 0);
        $previous = (float) ($period['previous_month_collected'] ?? 0);
        $growth = $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : ($current > 0 ? 100.0 : 0.0);

        return [
            'intent' => 'compare_collections',
            'answer' => sprintf(
                'This month collected %s versus %s last month (%s%% change).',
                number_format($current, 2),
                number_format($previous, 2),
                number_format($growth, 1)
            ),
            'metrics' => [
                'current_month_collected' => round($current, 2),
                'previous_month_collected' => round($previous, 2),
                'growth_pct' => $growth,
            ],
            'period_collections' => $period,
            'recommended_actions' => [
                'Inspect the collection trend chart for seasonal patterns.',
                'Follow up with overdue families if growth is negative.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatsAppOutreachResponse(int $tenantId): array
    {
        $preview = $this->whatsAppOutreachService->preview($tenantId, null, 10);

        return [
            'intent' => 'whatsapp_outreach',
            'answer' => $preview['eligible_count'] > 0
                ? sprintf('%d overdue families are eligible for WhatsApp outreach.', $preview['eligible_count'])
                : 'No overdue families with outreach targets were found.',
            'outreach' => $preview,
            'recommended_actions' => $preview['eligible_count'] > 0
                ? [
                    'Preview outreach messages on the Financial Dashboard.',
                    'Queue WhatsApp reminders for families with valid phone numbers.',
                ]
                : ['Ensure family phone numbers are captured in member profiles.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function helpResponse(): array
    {
        return [
            'intent' => 'help',
            'answer' => 'Try asking about overdue families, lagging projects, forecasts, top contributors, month-over-month collections, or WhatsApp outreach.',
            'examples' => [
                'Show families with outstanding annual contributions',
                'Which projects are lagging on building funds?',
                'Forecast collections for the next 3 months',
                'Who are our top contributing families?',
                'Compare this month versus last month',
                'Prepare WhatsApp reminders for overdue families',
            ],
        ];
    }

    /**
     * @param array{type: string, score?: float, params: array<string, mixed>} $intent
     */
    private function shouldUseRollup(array $intent, ?string $role): bool
    {
        return in_array($role, ['SuperAdmin', 'EkklesiaAdmin', 'DioceseFinanceOfficer'], true)
            && in_array($intent['type'], ['overdue_families', 'project_lagging', 'top_contributors'], true);
    }
}
