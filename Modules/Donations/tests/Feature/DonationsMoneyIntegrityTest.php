<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsMoneyIntegrityTest extends DonationsCertificationTestCase
{
    #[Test]
    public function it_persists_exact_decimal_amounts_and_full_allocation_sum(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '100.00');

        $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '100.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => '100.00',
            ]],
        ]));

        $response->assertCreated();
        $payment = DonationPayment::query()->findOrFail($response->json('data.id'));
        $this->assertSame('100.00', (string) $payment->amount);
        $sum = '0.00';
        foreach (PaymentAllocation::where('payment_id', $payment->id)->get() as $allocation) {
            $sum = MoneyMath::add($sum, $allocation->amount);
        }
        $this->assertTrue(MoneyMath::equals($sum, $payment->amount));
        $this->assertSame('100.00', (string) $seed['due']->fresh()->amount_paid);
        $this->assertSame('paid', $seed['due']->fresh()->status);
    }

    #[Test]
    public function it_rejects_negative_zero_and_excess_scale_amounts(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '-1'))
            ->assertStatus(422);
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '0'))
            ->assertStatus(422);
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '1.001'))
            ->assertStatus(422);
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '1e6'))
            ->assertStatus(422);
        $this->assertSame(0, DonationPayment::query()->count());
    }

    #[Test]
    public function it_splits_due_overpay_into_advance_credit(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $seed = $this->seedDue($ctx['tenant']->id, null, '60.00');

        $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '100.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => '100.00',
            ]],
        ]));
        $response->assertCreated();
        $paymentId = $response->json('data.id');
        $this->assertSame('60.00', (string) ContributionDue::find($seed['due']->id)->amount_paid);
        $this->assertSame('paid', ContributionDue::find($seed['due']->id)->status);
        $advance = PaymentAllocation::where('payment_id', $paymentId)->where('allocatable_type', 'advance')->sum('amount');
        $this->assertTrue(MoneyMath::equals($advance, '40.00'));
    }

    #[Test]
    public function it_records_unallocated_remainder_as_advance(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '75.50'));
        $response->assertCreated();
        $advance = PaymentAllocation::where('payment_id', $response->json('data.id'))
            ->where('allocatable_type', 'advance')
            ->first();
        $this->assertNotNull($advance);
        $this->assertSame('75.50', (string) $advance->amount);
        $this->assertSame(1, DonationAuditLog::where('event', 'payment.created')->count());
        $this->assertSame(1, DonationReceipt::where('payment_id', $response->json('data.id'))->count());
    }

    #[Test]
    public function property_random_allocation_splits_preserve_invariants(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);

        for ($i = 0; $i < 12; $i++) {
            $dueAmount = MoneyMath::normalize(random_int(100, 50000) / 100);
            $paymentAmount = MoneyMath::add($dueAmount, MoneyMath::normalize(random_int(0, 20000) / 100));
            $seed = $this->seedDue($ctx['tenant']->id, null, $dueAmount);
            $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], $paymentAmount, [
                'allocations' => [[
                    'allocatable_type' => 'due',
                    'allocatable_id' => $seed['due']->id,
                    'amount' => $dueAmount,
                ]],
            ]));
            $response->assertCreated();
            $payment = DonationPayment::find($response->json('data.id'));
            $sum = '0.00';
            foreach (PaymentAllocation::where('payment_id', $payment->id)->get() as $allocation) {
                $sum = MoneyMath::add($sum, $allocation->amount);
            }
            $this->assertTrue(MoneyMath::equals($sum, $payment->amount), "Split {$i} failed: {$sum} vs {$payment->amount}");
            $this->assertTrue(MoneyMath::compare($seed['due']->fresh()->amount_paid, $seed['due']->amount_due) <= 0);
        }
    }
}
