<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownRefundParityTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function mtd_totals_match_across_dashboard_executive_forecast_and_drill_after_refund_statuses(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $date = now()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARITY-GOOD',
            'payer_name' => 'Good',
            'payment_date' => $date,
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARITY-PARTIAL',
            'payer_name' => 'Partial refund',
            'payment_date' => $date,
            'amount' => 100,
            'refunded_amount' => 25,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARITY-REFUNDED',
            'payer_name' => 'Refunded',
            'payment_date' => $date,
            'amount' => 500,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'refunded',
            'source_type' => 'general',
        ]);
        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-PARITY-REVERSED',
            'payer_name' => 'Reversed',
            'payment_date' => $date,
            'amount' => 500,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'reversed',
            'source_type' => 'general',
        ]);

        $expectedGross = 200.0;

        $dashboard = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $dashboardMtd = (float) $dashboard->json('data.period_collections.current_month_collected');
        $this->assertTrue(MoneyMath::equals($expectedGross, $dashboardMtd));

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $executive->json('data.metrics.current_month_collected')
        ));
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $executive->json('data.visuals.collections.current_month_collected')
        ));
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $executive->json('data.visuals.forecast.current_month_collected')
        ));

        $forecastApi = $this->getJson('/api/tenant/donations/dashboard/forecast?months=3')->assertOk();
        $forecastMtd = (float) ($forecastApi->json('data.signals.current_month_collected') ?? 0);
        $this->assertTrue(MoneyMath::equals($expectedGross, $forecastMtd));

        $collectionsDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));
        $collectionsDrill->assertOk();
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $collectionsDrill->json('data.context.expected_amount')
        ));

        $currentPeriod = DonationBusinessDate::today($this->tenant->id);
        $currentPeriod = substr($currentPeriod, 0, 7);
        $trendDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collection_trend',
            'data_element_id' => 'collected',
            'slice_id' => $currentPeriod,
        ]));
        $trendDrill->assertOk();
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $trendDrill->json('data.context.expected_amount')
        ));

        $forecastDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'month_end_forecast',
            'data_element_id' => 'collected',
            'slice_id' => $currentPeriod,
        ]));
        $forecastDrill->assertOk();
        $this->assertTrue(MoneyMath::equals(
            $expectedGross,
            (float) $forecastDrill->json('data.context.expected_amount')
        ));
    }
}
