<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Services\ExecutiveReportMetricsService;
use Modules\Donations\Services\FinancialHealthService;
use Modules\Donations\Tests\Concerns\AuthenticatesDonationsTenantAdmin;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExecutiveReportStewardshipPhaseBTest extends TestCase
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
    public function due_schedule_partition_sums_to_pending_dues(): void
    {
        $metrics = app(ExecutiveReportMetricsService::class);
        $partition = $metrics->dueSchedulePartition($this->tenant->id);
        $sum = $partition['overdue_amount']
            + $partition['next_14_days_amount']
            + $partition['later_remaining_amount'];

        $this->assertEqualsWithDelta(
            (float) $partition['pending_dues'],
            $sum,
            0.02,
            'Overdue + next 14 days + later remaining must partition pending dues without overlap.'
        );
    }

    #[Test]
    public function church_score_redistributes_project_weight_when_no_active_projects(): void
    {
        $health = app(FinancialHealthService::class);
        $withProject = $health->buildChurchScore([
            'participation_rate' => 10,
            'overdue_ratio_pct' => 0,
            'project_momentum_pct' => 0,
            'project_applicable' => true,
            'growth_health_score' => 50,
            'overdue_family_count' => 0,
        ]);
        $withoutProject = $health->buildChurchScore([
            'participation_rate' => 10,
            'overdue_ratio_pct' => 0,
            'project_momentum_pct' => 0,
            'project_applicable' => false,
            'growth_health_score' => 50,
            'overdue_family_count' => 0,
        ]);

        $this->assertGreaterThan($withProject['score'], $withoutProject['score']);
    }

    #[Test]
    public function tiny_prior_base_yields_neutral_growth_health(): void
    {
        $metrics = app(ExecutiveReportMetricsService::class);
        $analysis = $metrics->growthHealthFromCollections(10_000.0, 50.0);

        $this->assertTrue($analysis['tiny_base']);
        $this->assertSame(50.0, $analysis['growth_health_score']);
    }

    #[Test]
    public function collection_snapshot_uses_due_allocated_payments(): void
    {
        $this->tenant->update([
            'settings' => ['timezone' => 'UTC', 'language' => 'en', 'currency' => 'USD'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $fund = Fund::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'General Fund',
            'code' => 'GEN-B',
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $this->tenant->id,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'MONTHLY-B',
            'frequency' => 'monthly',
            'default_amount' => 200,
            'status' => 'active',
        ]);
        $due = ContributionDue::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-03',
            'due_date' => '2026-03-10',
            'amount_due' => 200,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $payment = DonationPayment::create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-SNAPSHOT-1',
            'payer_name' => 'Snapshot',
            'payment_date' => '2026-03-12',
            'amount' => 150,
            'currency' => 'USD',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        PaymentAllocation::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'allocatable_type' => 'due',
            'allocatable_id' => $due->id,
            'amount' => 80,
        ]);

        $metrics = app(ExecutiveReportMetricsService::class);
        $snapshot = $metrics->collectionSnapshot($this->tenant->id, 0);

        $this->assertSame('due_allocated', $snapshot['collected_label']);
        $this->assertSame(80.0, (float) $snapshot['collected']);
        $this->assertSame(40.0, (float) $snapshot['collection_rate_pct']);
    }
}
