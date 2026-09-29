<?php

namespace Modules\Donations\Tests\Feature;

use Carbon\Carbon;
use Laravel\Passport\Passport;
use Modules\BCC\Models\BCC;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationPaymentsIndexTest extends DonationsCertificationTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function unscoped_index_stays_compatible_and_adds_meta(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id, 'status' => 'active']);
        $this->makePayment($ctx['tenant']->id, $family, '10.00', '2026-01-01', 'succeeded');

        $response = $this->getJson('/api/tenant/donations/payments')->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertIsArray($response->json('data.data'));
        $this->assertSame('unscoped', $response->json('meta.date_mode'));
        $this->assertSame('payment_date', $response->json('meta.date_basis'));
        $this->assertNull($response->json('meta.business_date'));
        $this->assertSame(1, (int) $response->json('meta.totals.payment_count'));
        $this->assertSame(10.0, (float) $response->json('meta.totals.collected_gross'));
        $this->assertArrayHasKey('refundable_remaining', $response->json('data.data.0'));
    }

    #[Test]
    public function paid_from_and_paid_to_keep_payment_register_range_semantics(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $inside = $this->makePayment($tenantId, $family, '20.00', '2026-06-15', 'succeeded');
        $this->makePayment($tenantId, $family, '5.00', '2026-06-01', 'succeeded');

        $ids = collect($this->getJson('/api/tenant/donations/payments?paid_from=2026-06-10&paid_to=2026-06-20')
            ->assertOk()
            ->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($inside->id));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?paid_from=2026-06-10&paid_to=2026-06-20')->json('meta.totals.payment_count'));
        $this->assertSame('paid_range', $this->getJson('/api/tenant/donations/payments?paid_from=2026-06-10&paid_to=2026-06-20')->json('meta.date_mode'));
    }

    #[Test]
    public function legacy_payment_date_aliases_filter_the_same_window(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $todayPayment = $this->makePayment($tenantId, $family, '15.00', $today, 'succeeded');
        $this->makePayment($tenantId, $family, '7.00', Carbon::parse($today)->subDay()->toDateString(), 'succeeded');

        $ids = collect($this->getJson('/api/tenant/donations/payments?payment_date_from='.$today.'&payment_date_to='.$today.'&per_page=8')
            ->assertOk()
            ->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($todayPayment->id));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?payment_date_from='.$today.'&payment_date_to='.$today)->json('meta.totals.payment_count'));
    }

    #[Test]
    public function today_only_uses_parish_timezone_and_payment_date_not_created_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 14:00:00', 'UTC'));
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $ctx['tenant']->update([
            'settings' => ['timezone' => 'Pacific/Auckland', 'language' => 'en', 'currency' => 'USD'],
        ]);
        $tenantId = (int) $ctx['tenant']->id;
        $parishToday = DonationBusinessDate::today($tenantId);
        $this->assertSame('2026-02-01', $parishToday);

        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $todayRow = $this->makePayment($tenantId, $family, '30.00', $parishToday, 'succeeded');
        $todayRow->created_at = Carbon::parse('2026-02-01 00:20:00', 'Pacific/Auckland');
        $todayRow->save();
        $yesterdayRow = $this->makePayment($tenantId, $family, '9.00', '2026-01-31', 'succeeded');
        $yesterdayRow->created_at = Carbon::parse('2026-02-01 01:00:00', 'Pacific/Auckland');
        $yesterdayRow->save();

        $response = $this->getJson('/api/tenant/donations/payments?today_only=1')->assertOk();
        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($todayRow->id));
        $this->assertFalse($ids->contains($yesterdayRow->id));
        $this->assertSame('2026-02-01', $response->json('meta.business_date'));
        $this->assertSame('Pacific/Auckland', $response->json('meta.timezone'));
        $this->assertSame('today', $response->json('meta.date_mode'));
        $this->assertSame('payment_date', $response->json('meta.date_basis'));
        $this->assertSame(1, (int) $response->json('meta.totals.payment_count'));
    }

    #[Test]
    public function backdated_and_delayed_entries_follow_payment_date(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $backdated = $this->makePayment($tenantId, $family, '40.00', $yesterday, 'succeeded');
        $backdated->created_at = now();
        $backdated->save();

        $this->getJson('/api/tenant/donations/payments?today_only=1')->assertOk()
            ->assertJsonPath('meta.totals.payment_count', 0);

        $override = $this->getJson('/api/tenant/donations/payments?collection_date='.$yesterday)->assertOk();
        $this->assertSame(1, (int) $override->json('meta.totals.payment_count'));
        $this->assertSame($yesterday, $override->json('meta.business_date'));
        $this->assertSame('collection_date', $override->json('meta.date_mode'));
    }

    #[Test]
    public function totals_include_all_statuses_but_gross_only_succeeded_and_partial_refunds(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $familyA = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $familyB = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);

        $this->makePayment($tenantId, $familyA, '100.00', $today, 'succeeded');
        $this->makePayment($tenantId, $familyA, '25.00', $today, 'succeeded', '10.00');
        $this->makePayment($tenantId, $familyB, '40.00', $today, 'refunded', '40.00');
        $this->makePayment($tenantId, $familyB, '12.00', $today, 'reversed');
        $this->makePayment($tenantId, $familyB, '8.00', $today, 'failed');
        $this->makePayment($tenantId, $familyB, '6.00', $today, 'pending');
        $anonymous = DonationPayment::create([
            'tenant_id' => $tenantId,
            'family_id' => null,
            'payment_number' => 'PAY-ANON-'.substr(uniqid(), -6),
            'payer_name' => 'Hidden Name',
            'payment_date' => $today,
            'amount' => '15.00',
            'refunded_amount' => '0.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
            'is_anonymous' => true,
        ]);

        $multi = $this->makePayment($tenantId, $familyA, '50.00', $today, 'succeeded');
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $multi->id,
            'allocatable_type' => 'advance',
            'allocatable_id' => $multi->id,
            'amount' => '20.00',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $multi->id,
            'allocatable_type' => 'advance',
            'allocatable_id' => $multi->id,
            'amount' => '30.00',
        ]);

        $response = $this->getJson('/api/tenant/donations/payments?today_only=1&per_page=2')->assertOk();
        $totals = $response->json('meta.totals');

        $this->assertSame(8, (int) $totals['payment_count']);
        $this->assertSame(2, count($response->json('data.data')));
        $this->assertSame(190.0, (float) $totals['collected_gross']);
        $this->assertSame(50.0, (float) $totals['refunded_total']);
        $this->assertSame(140.0, (float) $totals['net_collected']);
        $this->assertSame(2, (int) $totals['families_count']);
        $this->assertTrue(MoneyMath::equals($totals['net_collected'], MoneyMath::subtract($totals['collected_gross'], $totals['refunded_total'])));

        $failed = $this->getJson('/api/tenant/donations/payments?today_only=1&status=failed')->assertOk();
        $this->assertSame(1, (int) $failed->json('meta.totals.payment_count'));
        $this->assertSame(0.0, (float) $failed->json('meta.totals.collected_gross'));
        $anonymousRow = collect($this->getJson('/api/tenant/donations/payments?today_only=1&per_page=100')->json('data.data'))
            ->firstWhere('id', $anonymous->id);
        $this->assertNotNull($anonymousRow);
        $this->assertNull($anonymousRow['family']);
    }

    #[Test]
    public function search_covers_approved_fields_and_respects_anonymous_privacy(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'family_name' => 'Ward Family',
            'family_code' => 'FAM-SRCH',
        ]);
        $named = $this->makePayment($tenantId, $family, '11.00', $today, 'succeeded');
        $named->forceFill([
            'payment_number' => 'PAY-VISIBLE',
            'payer_name' => 'Jane Ward',
            'gateway_reference' => 'UPI-9988',
        ])->save();
        DonationReceipt::create([
            'tenant_id' => $tenantId,
            'payment_id' => $named->id,
            'receipt_number' => 'RCT-SRCH',
            'issued_on' => $today,
            'is_void' => false,
            'snapshot' => ['amount' => 11],
        ]);

        $secretFamily = Family::factory()->create([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'family_name' => 'Secret Household',
        ]);
        $anon = $this->makePayment($tenantId, $secretFamily, '9.00', $today, 'succeeded');
        $anon->forceFill([
            'is_anonymous' => true,
            'payer_name' => 'Secret Household',
            'payment_number' => 'PAY-HIDDEN',
        ])->save();

        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=Jane')->json('meta.totals.payment_count'));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=PAY-VISIBLE')->json('meta.totals.payment_count'));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=FAM-SRCH')->json('meta.totals.payment_count'));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=RCT-SRCH')->json('meta.totals.payment_count'));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=UPI-9988')->json('meta.totals.payment_count'));
        $this->assertSame(0, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=Secret')->json('meta.totals.payment_count'));
        $this->assertSame(2, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=x')->json('meta.totals.payment_count'));

        $wildcard = $this->makePayment($tenantId, $family, '3.00', $today, 'succeeded');
        $wildcard->forceFill(['payer_name' => 'Percent%Name', 'payment_number' => 'PAY-WILD'])->save();
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search=Percent')->json('meta.totals.payment_count'));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search='.rawurlencode('Percent%Name'))->json('meta.totals.payment_count'));
        $this->assertSame(0, (int) $this->getJson('/api/tenant/donations/payments?today_only=1&search='.rawurlencode('%%'))->json('meta.totals.payment_count'));
    }

    #[Test]
    public function filters_sort_pagination_and_validation_are_strict(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $cash = $this->makePayment($tenantId, $family, '5.00', $today, 'succeeded');
        $cash->forceFill(['method' => 'cash', 'payment_number' => 'PAY-AAA'])->save();
        $upi = $this->makePayment($tenantId, $family, '6.00', $today, 'succeeded');
        $upi->forceFill(['method' => 'online_placeholder', 'payment_number' => 'PAY-BBB'])->save();

        $cashOnly = $this->getJson('/api/tenant/donations/payments?today_only=1&method=cash')->assertOk();
        $this->assertSame(['PAY-AAA'], collect($cashOnly->json('data.data'))->pluck('payment_number')->all());

        $sorted = $this->getJson('/api/tenant/donations/payments?today_only=1&sort=payment_number&direction=desc')->assertOk();
        $this->assertSame(['PAY-BBB', 'PAY-AAA'], collect($sorted->json('data.data'))->pluck('payment_number')->all());

        $page1 = $this->getJson('/api/tenant/donations/payments?today_only=1&sort=payment_number&direction=asc&per_page=1&page=1')->assertOk();
        $page2 = $this->getJson('/api/tenant/donations/payments?today_only=1&sort=payment_number&direction=asc&per_page=1&page=2')->assertOk();
        $this->assertSame('PAY-AAA', $page1->json('data.data.0.payment_number'));
        $this->assertSame('PAY-BBB', $page2->json('data.data.0.payment_number'));
        $this->assertSame(2, (int) $page1->json('meta.totals.payment_count'));

        $this->getJson('/api/tenant/donations/payments?status=not-a-status')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?sort=secret_column')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?search='.str_repeat('a', 121))->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?per_page=200')
            ->assertOk()
            ->assertJsonPath('data.per_page', 100);
        $this->getJson('/api/tenant/donations/payments?paid_from=not-a-date')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?today_only=1&collection_date=2020-01-01')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?bcc_id=00000000-0000-4000-8000-000000000099')->assertStatus(422);
        $this->getJson('/api/tenant/donations/payments?project_id=00000000-0000-4000-8000-000000000099')->assertStatus(422);
        $this->assertNotNull($cash->id);
        $this->assertNotNull($upi->id);
    }

    #[Test]
    public function bcc_and_project_filters_are_tenant_owned_and_do_not_duplicate_rows(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $bcc = BCC::factory()->active()->create(['tenant_id' => $tenantId]);
        $family = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active', 'bcc_id' => $bcc->id]);
        $otherFamily = Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $project = DonationProject::create([
            'tenant_id' => $tenantId,
            'name' => 'Hall',
            'code' => 'HALL-'.substr(uniqid(), -6),
            'status' => 'active',
            'target_amount' => 0,
            'raised_amount' => 0,
        ]);

        $matched = $this->makePayment($tenantId, $family, '22.00', $today, 'succeeded');
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $matched->id,
            'allocatable_type' => 'project',
            'allocatable_id' => $project->id,
            'amount' => '12.00',
        ]);
        PaymentAllocation::create([
            'tenant_id' => $tenantId,
            'payment_id' => $matched->id,
            'allocatable_type' => 'project',
            'allocatable_id' => $project->id,
            'amount' => '10.00',
        ]);
        $this->makePayment($tenantId, $otherFamily, '4.00', $today, 'succeeded');

        $bccFiltered = $this->getJson('/api/tenant/donations/payments?today_only=1&bcc_id='.$bcc->id)->assertOk();
        $this->assertSame(1, (int) $bccFiltered->json('meta.totals.payment_count'));
        $this->assertSame(22.0, (float) $bccFiltered->json('meta.totals.collected_gross'));

        $projectFiltered = $this->getJson('/api/tenant/donations/payments?today_only=1&project_id='.$project->id)->assertOk();
        $this->assertSame(1, (int) $projectFiltered->json('meta.totals.payment_count'));
        $this->assertSame(22.0, (float) $projectFiltered->json('meta.totals.collected_gross'));

        $other = $this->makeTenantUser(['donations.view']);
        $foreignBcc = BCC::factory()->active()->create(['tenant_id' => $other['tenant']->id]);
        $this->getJson('/api/tenant/donations/payments?bcc_id='.$foreignBcc->id)->assertStatus(422);
    }

    #[Test]
    public function tenant_isolation_and_permissions_are_enforced(): void
    {
        $a = $this->actingAsTenantWith(['donations.view']);
        $familyA = Family::factory()->create(['tenant_id' => $a['tenant']->id, 'status' => 'active']);
        $mine = $this->makePayment($a['tenant']->id, $familyA, '18.00', DonationBusinessDate::today($a['tenant']->id), 'succeeded');

        $b = $this->makeTenantUser(['donations.view']);
        $familyB = Family::factory()->create(['tenant_id' => $b['tenant']->id, 'status' => 'active']);
        $foreign = $this->makePayment($b['tenant']->id, $familyB, '99.00', DonationBusinessDate::today($b['tenant']->id), 'succeeded');

        $ids = collect($this->getJson('/api/tenant/donations/payments?today_only=1')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));

        $this->getJson('/api/tenant/donations/payments?tenant_id='.$b['tenant']->id.'&today_only=1')->assertOk();
        $this->assertFalse(collect($this->getJson('/api/tenant/donations/payments?today_only=1')->json('data.data'))->pluck('id')->contains($foreign->id));

        $this->actingAsTenantWith([]);
        $this->getJson('/api/tenant/donations/payments')->assertForbidden();

        Passport::actingAs($a['user']);
        $this->postJson('/api/tenant/donations/payments/'.$mine->id.'/reverse', ['reason' => 'test'])->assertForbidden();
        $this->postJson('/api/tenant/donations/payments/'.$mine->id.'/refunds', [
            'amount' => 1,
            'refund_date' => DonationBusinessDate::today($a['tenant']->id),
            'reason' => 'test',
        ])->assertForbidden();
    }

    #[Test]
    public function empty_today_list_returns_zero_totals_without_omitting_keys(): void
    {
        $this->actingAsTenantWith(['donations.view']);

        $response = $this->getJson('/api/tenant/donations/payments?today_only=1')->assertOk();
        $totals = $response->json('meta.totals');

        $this->assertSame(0, (int) $totals['payment_count']);
        $this->assertSame(0.0, (float) $totals['collected_gross']);
        $this->assertSame(0.0, (float) $totals['refunded_total']);
        $this->assertSame(0.0, (float) $totals['net_collected']);
        $this->assertSame(0, (int) $totals['families_count']);
        $this->assertArrayHasKey('currency_code', $totals);
        $this->assertSame('today', $response->json('meta.date_mode'));
        $this->assertSame('payment_date', $response->json('meta.date_basis'));
        $this->assertNotNull($response->json('meta.business_date'));
        $this->assertSame([], $response->json('data.data'));
    }

    #[Test]
    public function unauthenticated_index_is_unauthorized(): void
    {
        $this->getJson('/api/tenant/donations/payments')->assertUnauthorized();
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
            'payer_name' => 'List Payer',
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
