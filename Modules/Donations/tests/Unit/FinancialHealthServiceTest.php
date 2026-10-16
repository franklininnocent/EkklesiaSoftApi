<?php

namespace Modules\Donations\Tests\Unit;

use Modules\Donations\Services\FinancialHealthService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinancialHealthServiceTest extends TestCase
{
    #[Test]
    public function church_score_uses_the_four_factor_weights_when_projects_apply(): void
    {
        $health = app(FinancialHealthService::class);
        $result = $health->buildChurchScore([
            'participation_rate' => 5,
            'overdue_ratio_pct' => 48,
            'project_momentum_pct' => 9,
            'project_applicable' => true,
            'growth_health_score' => 100,
            'overdue_family_count' => 12,
        ]);

        $this->assertEqualsWithDelta(34.2, $result['score'], 0.05);
        $this->assertSame('risk', $result['status']);
    }

    #[Test]
    public function risk_summary_pairs_overdue_families_with_the_strongest_positive_driver(): void
    {
        $health = app(FinancialHealthService::class);
        $result = $health->buildChurchScore([
            'participation_rate' => 4.8,
            'overdue_ratio_pct' => 80,
            'project_momentum_pct' => 9,
            'project_applicable' => true,
            'growth_health_score' => 50,
            'collection_growth_pct' => 0,
            'current_month_collected' => 6450,
            'overdue_family_count' => 2782,
        ]);

        $this->assertSame('risk', $result['status']);
        $this->assertStringContainsString('2782 families are significantly overdue', $result['summary']);
        $this->assertStringContainsString('Collections were recorded this month.', $result['summary']);
        $this->assertStringNotContainsString('₹', $result['summary']);
    }
}
