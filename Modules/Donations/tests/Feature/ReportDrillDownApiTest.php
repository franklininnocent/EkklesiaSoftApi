<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\ReportMetricCatalog;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportDrillDownApiTest extends TestCase
{
    use AuthenticatesDonationsTenantAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDonationsTenantAdmin();
    }

    #[Test]
    public function it_rejects_invalid_graph_slice_combination_with_422(): void
    {
        $response = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'overdue',
        ]));

        $response->assertStatus(422);
    }

    #[Test]
    public function collection_snapshot_drill_down_loads_with_modal_default_sort(): void
    {
        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collection_snapshot',
            'data_element_id' => 'outstanding',
            'slice_id' => 'outstanding',
            'sort' => 'expected',
            'direction' => 'desc',
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.graph_id', 'collection_snapshot')
            ->assertJsonPath('data.context.record_kind', 'none')
            ->assertJsonPath('data.context.point_kind', 'kpi');

        $legacySort = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collection_snapshot',
            'data_element_id' => 'expected',
            'slice_id' => 'expected',
            'sort' => 'outstanding_amount',
            'direction' => 'desc',
        ]));
        $legacySort->assertStatus(422);
    }

    #[Test]
    public function it_reconciles_participation_drill_down_with_executive_counts(): void
    {
        $paying = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $paying->id,
            'payment_number' => 'PAY-PART-1',
            'payer_name' => 'Participant',
            'payment_date' => DonationBusinessDate::today($this->tenant->id),
            'amount' => 100,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();

        $active = (int) $executive->json('data.visuals.participation.active_families');
        $participating = (int) $executive->json('data.visuals.participation.participating_families');
        $this->assertGreaterThanOrEqual(2, $active);
        $this->assertGreaterThanOrEqual(1, $participating);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'family_participation',
            'data_element_id' => 'participating_families',
            'slice_id' => 'participating',
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.value_kind', 'count')
            ->assertJsonPath('data.context.expected_count', $participating);
    }

    #[Test]
    public function it_excludes_future_dated_payments_from_mtd_collections_drill_down(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $futureDate = now()->copy()->addMonth()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-FUTURE',
            'payer_name' => 'Future',
            'payment_date' => $futureDate,
            'amount' => 9999,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collections',
            'data_element_id' => 'current_month_collected',
            'slice_id' => 'current_month',
        ]));

        $drill->assertOk();
        $this->assertSame(0, (int) $drill->json('data.data.total'));
        $this->assertSame(0.0, (float) $drill->json('data.context.expected_amount'));
    }

    #[Test]
    public function family_engagement_drill_down_lists_participating_families_with_modal_default_sort(): void
    {
        $paying = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $paying->id,
            'payment_number' => 'PAY-HEALTH-ENG-1',
            'payer_name' => 'Engaged',
            'payment_date' => DonationBusinessDate::today($this->tenant->id),
            'amount' => 50,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $participatingDrill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'family_participation',
            'data_element_id' => 'participating_families',
            'slice_id' => 'participating',
        ]));
        $participatingDrill->assertOk();

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'family_engagement',
            'slice_id' => 'family_engagement',
            'sort' => 'outstanding_amount',
            'direction' => 'desc',
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.graph_id', 'financial_health')
            ->assertJsonPath('data.context.data_element_id', 'family_engagement')
            ->assertJsonPath('data.context.slice_id', 'family_engagement')
            ->assertJsonPath('data.context.value_kind', 'score')
            ->assertJsonPath('data.context.record_kind', 'family')
            ->assertJsonPath('data.data.total', $participatingDrill->json('data.data.total'));
    }

    #[Test]
    public function it_reconciles_health_overall_score_with_weighted_factors(): void
    {
        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();

        $factors = $executive->json('data.visuals.health.factors') ?? [];
        $this->assertNotEmpty($factors);

        $weighted = round(collect($factors)->reduce(function (float $carry, array $factor): float {
            if (($factor['not_applicable'] ?? false) === true) {
                return $carry;
            }

            return $carry + ((float) ($factor['score'] ?? 0) * ((float) ($factor['weight_pct'] ?? 0) / 100));
        }, 0.0), 1);

        $this->assertSame(
            (float) $executive->json('data.visuals.health.score'),
            $weighted
        );

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'overall_score',
            'slice_id' => 'overall_score',
        ]));

        $drill->assertOk()
            ->assertJsonPath('data.context.point_kind', 'kpi')
            ->assertJsonPath('data.context.expected_amount', $weighted)
            ->assertJsonPath('data.data.total', 0);
    }

    #[Test]
    public function it_reconciles_trend_current_month_with_collections_mtd(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $thisMonth = now()->startOfMonth()->toDateString();

        DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-TREND-MTD',
            'payer_name' => 'Trend',
            'payment_date' => $thisMonth,
            'amount' => 175,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary');
        $executive->assertOk();

        $mtd = (float) $executive->json('data.visuals.collections.current_month_collected');
        $currentPeriod = now()->format('Y-m');
        $trend = collect($executive->json('data.visuals.collection_trend') ?? [])
            ->firstWhere('period', $currentPeriod);
        $this->assertNotNull($trend);
        $this->assertSame($mtd, (float) $trend['collected']);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'collection_trend',
            'data_element_id' => 'collected',
            'slice_id' => $currentPeriod,
        ]));

        $drill->assertOk();
        $this->assertSame($mtd, (float) $drill->json('data.context.expected_amount'));
    }

    #[Test]
    public function leadership_graph_ids_match_catalog(): void
    {
        $this->assertSame(ReportMetricCatalog::LEADERSHIP_GRAPH_IDS, [
            'outstanding_overdue',
            'collections',
            'collection_snapshot',
            'collection_trend',
            'family_participation',
            'month_end_forecast',
            'financial_health',
        ]);
    }

    #[Test]
    public function growth_health_drill_down_matches_executive_factor(): void
    {
        $executive = $this->getJson('/api/tenant/donations/reports/executive-summary')->assertOk();
        $factor = collect($executive->json('data.visuals.health.factors') ?? [])
            ->firstWhere('key', 'growth_health');
        $this->assertNotNull($factor);

        $drill = $this->getJson('/api/tenant/donations/reports/drill-down?'.http_build_query([
            'graph_id' => 'financial_health',
            'data_element_id' => 'growth_health',
            'slice_id' => 'growth_health',
        ]));
        $drill->assertOk()
            ->assertJsonPath('data.context.value_kind', 'score');
        $this->assertEquals(
            (float) $factor['score'],
            (float) $drill->json('data.context.expected_amount')
        );
    }
}
