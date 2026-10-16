<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownGrowthMatrixTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function growth_health_drill_scores_zero_to_zero_as_fifty(): void
    {
        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'growth_health',
            'slice_id' => 'growth_health',
        ]));
        $drill->assertOk();
        $this->assertSame(50.0, (float) $drill->json('data.context.expected_amount'));
    }

    #[Test]
    public function growth_health_drill_scores_zero_to_positive_as_neutral_fifty(): void
    {
        $this->tenant->update([
            'settings' => ['timezone' => 'UTC', 'language' => 'en', 'currency' => 'USD'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-GROW-1',
            'payer_name' => 'Growth',
            'payment_date' => '2026-03-10',
            'amount' => 120,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $metrics = app(ExecutiveReportMetricsService::class);
        $this->assertSame(0.0, $metrics->collectionGrowthPct($this->tenant->id));
        $analysis = $metrics->collectionGrowthAnalysis($this->tenant->id);
        $this->assertFalse($analysis['comparison_available']);
        $this->assertSame(50.0, $analysis['growth_health_score']);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'growth_health',
            'slice_id' => 'growth_health',
        ]));
        $drill->assertOk();
        $this->assertSame(50.0, (float) $drill->json('data.context.expected_amount'));

        $collections = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));
        $collections->assertOk();
        $this->assertSame(120.0, (float) $collections->json('data.context.expected_amount'));
    }

    #[Test]
    public function growth_pct_guard_matches_metrics_service(): void
    {
        $metrics = app(ExecutiveReportMetricsService::class);
        $this->assertSame(0.0, $metrics->growthPct(0, 0));
        $this->assertSame(0.0, $metrics->growthPct(50, 0));
        $this->assertSame(-100.0, $metrics->growthPct(0, 40));
        $this->assertSame(100.0, $metrics->growthPct(200, 100));
    }
}
