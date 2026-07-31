<?php

namespace Modules\Donations\Services;

class FinancialHealthService
{
    /**
     * @param array<string, float|int> $inputs
     * @return array{score: float, label: string, status: string}
     */
    public function buildFamilyScore(array $inputs): array
    {
        $punctuality = min(100, max(0, (float) ($inputs['punctuality_score'] ?? 100)));
        $mandatoryCompliance = min(100, max(0, (float) ($inputs['mandatory_compliance_pct'] ?? 100)));
        $projectParticipation = min(100, max(0, (float) ($inputs['project_participation_pct'] ?? 100)));
        $voluntaryEngagement = min(100, max(0, (float) ($inputs['voluntary_engagement_pct'] ?? 0)));

        $score = round(
            ($punctuality * 0.35)
            + ($mandatoryCompliance * 0.30)
            + ($projectParticipation * 0.20)
            + ($voluntaryEngagement * 0.15),
            1
        );

        return $this->formatScore($score);
    }

    /**
     * @param array<string, float|int> $inputs
     * @return array{score: float, label: string, status: string, summary: string}
     */
    public function buildChurchScore(array $inputs): array
    {
        $collectionRate = min(100, max(0, (float) ($inputs['participation_rate'] ?? 0)));
        $overdueHealth = min(100, max(0, 100 - (float) ($inputs['overdue_ratio_pct'] ?? 0)));
        $projectMomentum = min(100, max(0, (float) ($inputs['project_momentum_pct'] ?? 0)));
        $growthHealth = min(100, max(0, 50 + ((float) ($inputs['collection_growth_pct'] ?? 0) / 2)));

        $score = round(
            ($collectionRate * 0.35)
            + ($overdueHealth * 0.30)
            + ($projectMomentum * 0.20)
            + ($growthHealth * 0.15),
            1
        );

        $formatted = $this->formatScore($score);
        $formatted['summary'] = $this->churchSummary($formatted['status'], $inputs);

        return $formatted;
    }

    /**
     * @return array{score: float, label: string, status: string}
     */
    private function formatScore(float $score): array
    {
        $status = match (true) {
            $score >= 80 => 'healthy',
            $score >= 50 => 'attention',
            default => 'risk',
        };

        $label = match ($status) {
            'healthy' => 'Healthy',
            'attention' => 'Attention Needed',
            default => 'High Risk',
        };

        return [
            'score' => $score,
            'label' => $label,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, float|int> $inputs
     */
    private function churchSummary(string $status, array $inputs): string
    {
        $overdueFamilies = (int) ($inputs['overdue_family_count'] ?? 0);
        $participation = round((float) ($inputs['participation_rate'] ?? 0), 1);

        return match ($status) {
            'healthy' => "Strong participation at {$participation}%. Collections are on track.",
            'attention' => "{$overdueFamilies} families need follow-up. Participation is {$participation}%.",
            default => "Immediate attention required — {$overdueFamilies} families are significantly overdue.",
        };
    }
}
