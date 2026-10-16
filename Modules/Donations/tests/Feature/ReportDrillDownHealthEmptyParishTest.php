<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownHealthEmptyParishTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function empty_parish_health_drill_scores_use_plan_guards_not_zero(): void
    {
        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $factors = collect($executive->json('data.visuals.health.factors') ?? [])->keyBy('key');

        $this->assertSame(50.0, (float) ($factors['growth_health']['score'] ?? -1));
        $this->assertSame(100.0, (float) ($factors['overdue_health']['score'] ?? -1));

        $growthDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'growth_health',
            'slice_id' => 'growth_health',
        ]));
        $growthDrill->assertOk();
        $this->assertSame(50.0, (float) $growthDrill->json('data.context.expected_amount'));

        $overdueDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'overdue_health',
            'slice_id' => 'overdue_health',
        ]));
        $overdueDrill->assertOk();
        $this->assertSame(100.0, (float) $overdueDrill->json('data.context.expected_amount'));
    }
}
