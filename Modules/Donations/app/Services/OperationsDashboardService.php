<?php

namespace Modules\Donations\Services;

use Modules\Authentication\Models\User;

class OperationsDashboardService
{
    public function __construct(
        private readonly DonationDashboardService $dashboardService,
        private readonly DashboardPersonaService $personaService,
        private readonly DonationSavedViewService $savedViewService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, User $user): array
    {
        $summary = $this->dashboardService->getSummary($tenantId);
        $persona = $this->personaService->resolve($user, $summary['tenant_context'] ?? null);

        return [
            'persona' => $persona,
            'financial' => [
                'health' => $summary['financial_health'] ?? null,
                'totals' => [
                    'collected' => (float) ($summary['totals']['collected'] ?? 0),
                    'pending_dues' => (float) ($summary['totals']['pending_dues'] ?? 0),
                    'current_month_collected' => (float) ($summary['period_collections']['current_month_collected'] ?? 0),
                    'annual_collected' => (float) ($summary['period_collections']['annual_collected'] ?? 0),
                ],
                'families' => $summary['families'] ?? [],
                'attention_summary' => $summary['attention_summary'] ?? ['count' => 0, 'total_overdue_amount' => 0],
            ],
            'families_requiring_attention' => array_slice($summary['families_requiring_attention'] ?? [], 0, 5),
            'recent_activity' => array_slice($summary['recent_activity'] ?? [], 0, 6),
            'active_projects' => array_slice($summary['active_project_summaries'] ?? [], 0, 3),
            'saved_views' => $this->savedViewService->presets(),
        ];
    }
}
