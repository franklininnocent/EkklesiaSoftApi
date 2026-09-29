<?php

namespace Modules\Donations\Services;

class FinancialHealthService
{
    /**
     * @param  array<string, float|int>  $inputs
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
     * @param  array<string, float|int>  $inputs
     * @return array{score: float, label: string, status: string, summary: string}
     */
    public function buildChurchScore(array $inputs): array
    {
        $collectionRate = min(100, max(0, (float) ($inputs['participation_rate'] ?? 0)));
        $overdueHealth = min(100, max(0, 100 - (float) ($inputs['overdue_ratio_pct'] ?? 0)));
        $projectApplicable = (bool) ($inputs['project_applicable'] ?? true);
        $projectMomentum = min(100, max(0, (float) ($inputs['project_momentum_pct'] ?? 0)));
        $growthHealth = min(100, max(0, (float) (
            $inputs['growth_health_score']
            ?? (50 + ((float) ($inputs['collection_growth_pct'] ?? 0) / 2))
        )));

        if ($projectApplicable) {
            $score = round(
                ($collectionRate * 0.35)
                + ($overdueHealth * 0.30)
                + ($projectMomentum * 0.20)
                + ($growthHealth * 0.15),
                1
            );
        } else {
            $score = round(
                ($collectionRate * 0.4375)
                + ($overdueHealth * 0.375)
                + ($growthHealth * 0.1875),
                1
            );
        }

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
     * @param  array<string, float|int>  $inputs
     */
    private function churchSummary(string $status, array $inputs): string
    {
        $overdueFamilies = (int) ($inputs['overdue_family_count'] ?? 0);
        $participation = round((float) ($inputs['participation_rate'] ?? 0), 1);
        $positiveNote = $this->strongestPositiveNote($inputs);

        return match ($status) {
            'healthy' => "Strong participation at {$participation}%. Collections are on track.",
            'attention' => "{$overdueFamilies} families need follow-up. Participation is {$participation}%.",
            default => $positiveNote !== null
                ? "Immediate attention required — {$overdueFamilies} families are significantly overdue. {$positiveNote}"
                : "Immediate attention required — {$overdueFamilies} families are significantly overdue.",
        };
    }

    /**
     * @param  array<string, float|int>  $inputs
     */
    private function strongestPositiveNote(array $inputs): ?string
    {
        $growth = (float) ($inputs['collection_growth_pct'] ?? 0);
        if ($growth >= 5) {
            return sprintf('Collection growth is up %.1f%% versus the same days last month.', $growth);
        }
        if ($growth > 0) {
            return sprintf('Collections increased %.1f%% versus the same days last month.', $growth);
        }

        $collected = (float) ($inputs['current_month_collected'] ?? 0);
        if ($collected > 0) {
            return 'Collections were recorded this month.';
        }

        $participation = (float) ($inputs['participation_rate'] ?? 0);
        if ($participation >= 60) {
            return sprintf('%.1f%% of active families contributed in the last 90 days.', $participation);
        }

        $projectMomentum = (float) ($inputs['project_momentum_pct'] ?? 0);
        if ($projectMomentum >= 50) {
            return sprintf('Active projects average %.1f%% funded.', $projectMomentum);
        }

        return null;
    }
}
