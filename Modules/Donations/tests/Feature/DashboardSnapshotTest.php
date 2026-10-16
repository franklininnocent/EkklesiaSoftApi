<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Models\Fund;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DashboardSnapshotTest extends DonationsCertificationTestCase
{
    #[Test]
    public function snapshot_uses_succeeded_collections_and_keeps_partial_refunds_in_collected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $this->makePayment($tenantId, $family, '100.00', $today, 'succeeded', '25.00');
        $this->makePayment($tenantId, $family, '10.00', $today, 'pending');
        $this->makePayment($tenantId, $family, '11.00', $today, 'failed');
        $this->makePayment($tenantId, $family, '12.00', $today, 'reversed');
        $this->makePayment($tenantId, $family, '13.00', $today, 'refunded');
        $this->makePayment($tenantId, $family, '14.00', Carbon::parse($today)->addDay()->toDateString(), 'succeeded');

        $snapshot = $this->getJson('/api/tenant/donations/dashboard/summary')
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame(100.0, (float) $snapshot['month']['collected']);
        $this->assertSame(100.0, (float) $snapshot['fiscal_year_collected']);
        $this->assertNull($snapshot['month']['growth_pct']);
        $this->assertSame(0.0, (float) $snapshot['month']['comparison_collected']);
    }

    #[Test]
    public function full_refund_is_excluded_from_collected_and_totals_net_ignores_double_subtraction(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $this->makePayment($tenantId, $family, '40.00', $today, 'succeeded');
        $refunded = $this->makePayment($tenantId, $family, '100.00', $today, 'refunded');
        DonationRefund::create([
            'tenant_id' => $tenantId,
            'payment_id' => $refunded->id,
            'amount' => '100.00',
            'refund_date' => $today,
            'status' => 'completed',
            'reason' => 'Full refund already removed the payment from succeeded collections.',
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();

        $this->assertSame(40.0, (float) $response->json('data.totals.collected'));
        $this->assertSame(100.0, (float) $response->json('data.totals.refunded'));
        $this->assertSame(40.0, (float) $response->json('data.totals.net'));
        $this->assertArrayNotHasKey('net', $response->json('data.snapshot'));
        $this->assertArrayNotHasKey('net_position', $response->json('data.snapshot'));
    }

    #[Test]
    public function outstanding_overdue_and_due_soon_stay_separate_from_project_installments(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $plan = $this->makePlan($tenantId);

        $this->makeDue($tenantId, $family, $plan, '10.00', $today, Carbon::parse($today)->subDays(10)->toDateString(), 'pending');
        $this->makeDue($tenantId, $family, $plan, '20.00', Carbon::parse($today)->subDay()->toDateString(), Carbon::parse($today)->subMonth()->toDateString(), 'pending');
        $this->makeDue($tenantId, $family, $plan, '30.00', Carbon::parse($today)->addDays(15)->toDateString(), Carbon::parse($today)->subDay()->toDateString(), 'pending');
        $this->makeDue($tenantId, $family, $plan, '40.00', Carbon::parse($today)->addDays(25)->toDateString(), Carbon::parse($today)->addDays(20)->toDateString(), 'pending');
        $this->makeDue($tenantId, $family, $plan, '50.00', Carbon::parse($today)->subDay()->toDateString(), Carbon::parse($today)->subMonth()->toDateString(), 'waived');
        $this->makeDue($tenantId, $family, $plan, '60.00', Carbon::parse($today)->subDay()->toDateString(), Carbon::parse($today)->subMonth()->toDateString(), 'cancelled');
        $this->makeDue($tenantId, $family, $plan, '70.00', Carbon::parse($today)->subDay()->toDateString(), Carbon::parse($today)->subMonth()->toDateString(), 'paid');

        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Hall',
            'code' => 'HALL-'.substr(uniqid(), -6),
            'status' => 'active',
            'target_amount' => 0,
            'raised_amount' => 0,
        ]);
        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'installment_number' => 1,
            'installment_label' => 'Overdue',
            'due_date' => Carbon::parse($today)->subDay()->toDateString(),
            'amount_due' => '80.00',
            'amount_paid' => '0.00',
            'status' => 'pending',
        ]);
        ProjectInstallmentDue::create([
            'tenant_id' => $tenantId,
            'project_id' => $project->id,
            'family_id' => $family->id,
            'installment_number' => 2,
            'installment_label' => 'Later',
            'due_date' => Carbon::parse($today)->addMonth()->toDateString(),
            'amount_due' => '15.00',
            'amount_paid' => '0.00',
            'status' => 'pending',
        ]);

        $snapshot = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk()->json('data.snapshot');

        $this->assertSame(60.0, (float) $snapshot['outstanding_contributions']);
        $this->assertSame(20.0, (float) $snapshot['overdue_amount']);
        $this->assertSame(1, (int) $snapshot['overdue_families']);
        $this->assertSame(10.0, (float) $snapshot['due_next_14_days_amount']);
        $this->assertSame(1, (int) $snapshot['due_next_14_days_families']);
        $this->assertArrayHasKey('due_later_amount', $snapshot);
        $this->assertSame(80.0, (float) $snapshot['project_installments']['overdue']);
        $this->assertSame(15.0, (float) $snapshot['project_installments']['not_yet_due']);
        $this->assertSame(95.0, (float) $snapshot['project_installments']['open']);
    }

    #[Test]
    public function participation_counts_only_active_families_with_a_payment_in_the_90_day_window(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);

        $current = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $quiet = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $inactive = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'inactive']);
        $lapsedWindow = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $this->makePayment($tenantId, $current, '25.00', $today, 'succeeded');
        $this->makePayment($tenantId, $inactive, '25.00', $today, 'succeeded');
        $this->makePayment($tenantId, $lapsedWindow, '25.00', Carbon::parse($today)->subDays(120)->toDateString(), 'succeeded');

        $participation = $this->getJson('/api/tenant/donations/dashboard/summary')
            ->assertOk()
            ->json('data.snapshot.participation');

        $this->assertSame(1, (int) $participation['participating']);
        $this->assertSame(3, (int) $participation['active']);
        $this->assertSame(2, (int) $participation['not_participating']);
        $this->assertSame(33.3, (float) $participation['rate']);
        $this->assertNotSame($quiet->id, $inactive->id);
    }

    #[Test]
    public function giving_mix_reconciles_to_fiscal_year_collected_including_unallocated_payments(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $plan = $this->makePlan($tenantId);
        $due = $this->makeDue($tenantId, $family, $plan, '40.00', $today, Carbon::parse($today)->subDays(5)->toDateString(), 'pending');
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Roof',
            'code' => 'ROOF-'.substr(uniqid(), -6),
            'status' => 'active',
            'target_amount' => 1000,
            'raised_amount' => 250,
        ]);

        $allocated = $this->makePayment($tenantId, $family, '100.00', $today, 'succeeded');
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $allocated->id,
            'allocatable_type' => 'due',
            'allocatable_id' => $due->id,
            'amount' => '40.00',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $allocated->id,
            'allocatable_type' => 'project',
            'allocatable_id' => $project->id,
            'amount' => '20.00',
        ]);
        $this->makePayment($tenantId, $family, '15.00', $today, 'succeeded');

        $other = $this->makeTenantUser(['donations.view']);
        $otherFamily = Family::factory()->create(['tenant_id' => $other['tenant']->id, 'status' => 'active']);
        $this->makePayment($other['tenant']->id, $otherFamily, '500.00', $today, 'succeeded');

        Passport::actingAs($ctx['user']);
        $snapshot = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk()->json('data.snapshot');
        $mix = $snapshot['giving_mix'];
        $byKey = collect($mix['buckets'])->keyBy('key');

        $this->assertSame(115.0, (float) $snapshot['fiscal_year_collected']);
        $this->assertTrue($mix['reconciled']);
        $this->assertSame(40.0, (float) $byKey['contribution_dues']['amount']);
        $this->assertSame(20.0, (float) $byKey['projects']['amount']);
        $this->assertSame(55.0, (float) $mix['unallocated']);
        $this->assertSame(0.0, (float) $byKey['voluntary']['amount']);
    }

    #[Test]
    public function project_filter_scopes_collected_trend_and_giving_mix_to_allocations(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $plan = $this->makePlan($tenantId);
        $due = $this->makeDue($tenantId, $family, $plan, '40.00', $today, Carbon::parse($today)->subDays(5)->toDateString(), 'pending');
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Roof',
            'code' => 'ROOF-'.substr(uniqid(), -6),
            'status' => 'active',
            'target_amount' => 1000,
            'raised_amount' => 250,
        ]);

        $payment = $this->makePayment($tenantId, $family, '100.00', $today, 'succeeded');
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'allocatable_type' => 'due',
            'allocatable_id' => $due->id,
            'amount' => '40.00',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $payment->id,
            'allocatable_type' => 'project',
            'allocatable_id' => $project->id,
            'amount' => '25.00',
        ]);

        Passport::actingAs($ctx['user']);
        $response = $this->getJson('/api/tenant/donations/dashboard/summary?preset=this_month&project_id='.$project->id)
            ->assertOk();
        $data = $response->json('data');

        $this->assertSame(25.0, (float) $data['snapshot']['month']['collected']);
        $this->assertTrue($data['snapshot']['giving_mix']['reconciled']);
        $projectBucket = collect($data['snapshot']['giving_mix']['buckets'])->firstWhere('key', 'projects');
        $this->assertSame(25.0, (float) ($projectBucket['amount'] ?? 0));
        $trendTotal = array_sum(array_map(fn (array $row): float => (float) $row['collected'], $data['collection_trend'] ?? []));
        $this->assertSame(25.0, $trendTotal);
    }

    #[Test]
    public function zero_project_target_is_not_rewritten_as_one(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        DonationProject::create([
            'tenant_id' => $ctx['tenant']->id,
            'name' => 'No Target',
            'code' => 'ZERO-'.substr(uniqid(), -6),
            'status' => 'active',
            'target_amount' => 0,
            'raised_amount' => 10,
        ]);

        $response = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $project = $response->json('data.snapshot.projects.0');

        $this->assertSame(0.0, (float) $project['target_amount']);
        $this->assertFalse($project['has_target']);
        $this->assertNull($project['funding_percentage']);
        $this->assertNull($project['remaining']);
        $this->assertSame(1.0, (float) $response->json('data.active_project_summaries.0.target_amount'));
    }

    #[Test]
    public function summary_and_payment_dates_stay_inside_the_acting_tenant(): void
    {
        $a = $this->actingAsTenantWith(['donations.view']);
        $today = DonationBusinessDate::today($a['tenant']->id);
        $familyA = Family::factory()->create(['tenant_id' => $a['tenant']->id, 'status' => 'active']);
        $inside = $this->makePayment($a['tenant']->id, $familyA, '30.00', $today, 'succeeded');
        $this->makePayment($a['tenant']->id, $familyA, '9.00', Carbon::parse($today)->subDays(40)->toDateString(), 'succeeded');

        $b = $this->makeTenantUser(['donations.view']);
        $familyB = Family::factory()->create(['tenant_id' => $b['tenant']->id, 'status' => 'active']);
        $foreign = $this->makePayment($b['tenant']->id, $familyB, '500.00', $today, 'succeeded');
        $planB = $this->makePlan($b['tenant']->id);
        $this->makeDue($b['tenant']->id, $familyB, $planB, '80.00', Carbon::parse($today)->subDay()->toDateString(), Carbon::parse($today)->subMonth()->toDateString(), 'pending');

        Passport::actingAs($a['user']);

        $summary = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $this->assertSame(30.0, (float) $summary->json('data.snapshot.month.collected'));
        $this->assertSame(0.0, (float) $summary->json('data.snapshot.outstanding_contributions'));

        $filtered = $this->getJson('/api/tenant/donations/payments?paid_from='.$today.'&paid_to='.$today)->assertOk();
        $ids = collect($filtered->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($inside->id));
        $this->assertFalse($ids->contains($foreign->id));

        $this->getJson('/api/tenant/donations/payments?paid_from=not-a-date')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?paid_from='.$today.'&paid_to='.Carbon::parse($today)->subDay()->toDateString())
            ->assertStatus(422);
    }

    #[Test]
    public function dashboard_summary_requires_authentication_and_donations_view(): void
    {
        $this->getJson('/api/tenant/donations/dashboard/summary')->assertStatus(401);

        $this->actingAsTenantWith([]);
        $this->getJson('/api/tenant/donations/dashboard/summary')->assertStatus(403);
    }

    #[Test]
    public function this_month_preset_filters_collections_and_participation_to_the_month(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $inMonth = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $outside = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $this->makePayment($tenantId, $inMonth, '40.00', $today, 'succeeded');
        $this->makePayment(
            $tenantId,
            $outside,
            '25.00',
            Carbon::parse($today)->subDays(40)->toDateString(),
            'succeeded'
        );

        $legacy = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $this->assertSame(40.0, (float) $legacy->json('data.snapshot.month.collected'));
        $this->assertSame(2, (int) $legacy->json('data.snapshot.participation.participating'));

        $filtered = $this->getJson('/api/tenant/donations/dashboard/summary?preset=this_month')->assertOk();
        $this->assertSame(40.0, (float) $filtered->json('data.snapshot.month.collected'));
        $this->assertSame(1, (int) $filtered->json('data.snapshot.participation.participating'));
        $this->assertSame('this_month', $filtered->json('data.applied_range.preset'));
        $this->assertSame('same_days_prior_month', $filtered->json('data.applied_range.comparison_mode'));
        $this->assertSame('period', $filtered->json('data.metric_basis.collections'));
        $this->assertSame('point_in_time', $filtered->json('data.metric_basis.outstanding'));
        $this->assertSame('operational_from_parish_today', $filtered->json('data.metric_basis.due_next_14_days'));
        $this->assertSame('parish_today', $filtered->json('data.snapshot.due_next_14_days_basis'));
    }

    #[Test]
    public function custom_range_is_inclusive_and_ignores_payments_outside_it(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $today = DonationBusinessDate::today($tenantId);
        $rangeStart = Carbon::parse($today)->subDays(12)->toDateString();
        $rangeEnd = Carbon::parse($today)->subDays(10)->toDateString();
        $afterEnd = Carbon::parse($today)->subDays(9)->toDateString();
        $resolved = DashboardDateRange::resolve($tenantId, $rangeStart, $rangeEnd, DashboardDateRange::PRESET_CUSTOM);

        $this->makePayment($tenantId, $family, '10.00', $resolved->comparisonEnd, 'succeeded');
        $this->makePayment($tenantId, $family, '20.00', $rangeStart, 'succeeded');
        $this->makePayment($tenantId, $family, '30.00', $rangeEnd, 'succeeded');
        $this->makePayment($tenantId, $family, '40.00', $afterEnd, 'succeeded');

        $snapshot = $this->getJson(
            '/api/tenant/donations/dashboard/summary?preset=custom&date_from='.$rangeStart.'&date_to='.$rangeEnd
        )
            ->assertOk()
            ->json('data.snapshot');

        $this->assertSame(50.0, (float) $snapshot['month']['collected']);
        $this->assertSame('equal_length_prior', $snapshot['applied_range']['comparison_mode']);
        $this->assertSame(10.0, (float) $snapshot['month']['comparison_collected']);
    }

    #[Test]
    public function inverted_and_overlong_ranges_are_rejected(): void
    {
        $this->actingAsTenantWith(['donations.view']);

        $this->getJson('/api/tenant/donations/dashboard/summary?preset=custom&date_from=2026-02-01&date_to=2026-01-01')
            ->assertStatus(422);
        $this->getJson('/api/tenant/donations/dashboard/summary?preset=custom&date_from=2010-01-01&date_to=2021-01-02')
            ->assertStatus(422);
        $this->getJson('/api/tenant/donations/dashboard/summary?date_from=2026-01-01')
            ->assertStatus(422);
    }

    private function makePlan(int $tenantId): ContributionPlan
    {
        $fund = Fund::create([
            'tenant_id' => $tenantId,
            'name' => 'General',
            'code' => 'GEN-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);

        return ContributionPlan::create([
            'tenant_id' => $tenantId,
            'fund_id' => $fund->id,
            'name' => 'Monthly',
            'code' => 'PLAN-'.substr(uniqid(), -6),
            'frequency' => 'monthly',
            'default_amount' => '100.00',
            'status' => 'active',
        ]);
    }

    private function makeDue(
        int $tenantId,
        Family $family,
        ContributionPlan $plan,
        string $amount,
        string $dueDate,
        string $periodStart,
        string $status
    ): ContributionDue {
        return ContributionDue::create([
            'tenant_id' => $tenantId,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => 'P-'.substr(uniqid(), -8),
            'period_start' => $periodStart,
            'period_end' => $dueDate,
            'due_date' => $dueDate,
            'amount_due' => $amount,
            'amount_paid' => 0,
            'status' => $status,
        ]);
    }

    private function makePayment(
        int $tenantId,
        Family $family,
        string $amount,
        string $date,
        string $status,
        string $refundedAmount = '0.00'
    ): DonationPayment {
        return DonationPayment::create([
            'tenant_id' => $tenantId,
            'family_id' => $family->id,
            'payment_number' => 'PAY-'.substr(uniqid(), -10),
            'payer_name' => 'Snapshot Payer',
            'payment_date' => $date,
            'amount' => $amount,
            'refunded_amount' => $refundedAmount,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => $status,
            'source_type' => 'general',
        ]);
    }
}
