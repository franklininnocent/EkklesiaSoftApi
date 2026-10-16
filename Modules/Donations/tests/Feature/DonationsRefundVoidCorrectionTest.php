<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Models\DonationRefund;
use Modules\Donations\Support\MoneyMath;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DonationsRefundVoidCorrectionTest extends DonationsCertificationTestCase
{
    private function collectUser(): array
    {
        return $this->actingAsTenantWith([
            'donations.view',
            'donations.collect',
            'donations.reverse',
            'donations.refund',
            'donations.approvals',
            'donations.manage',
        ]);
    }

    #[Test]
    public function reverse_unwinds_due_and_voids_receipt(): void
    {
        $ctx = $this->collectUser();
        $seed = $this->seedDue($ctx['tenant']->id, null, '200.00');
        $created = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '200.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => '200.00',
            ]],
        ]))->assertCreated();

        $paymentId = $created->json('data.id');
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'Entered on the wrong family'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame('0.00', (string) $seed['due']->fresh()->amount_paid);
        $this->assertSame('pending', $seed['due']->fresh()->status);
        $this->assertTrue((bool) DonationReceipt::where('payment_id', $paymentId)->first()->is_void);
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'again'])
            ->assertStatus(422);
    }

    #[Test]
    public function approved_refund_completes_ledger_and_caps_over_refund(): void
    {
        $ctx = $this->collectUser();
        $seed = $this->seedDue($ctx['tenant']->id, null, '100.00');
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '100.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => '100.00',
            ]],
        ]))->json('data.id');

        $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '150.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'Too much',
        ])->assertStatus(422);

        $refund = $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '40.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'Partial return',
        ])->assertCreated()->json('data');

        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'approved',
            'note' => 'OK',
        ])->assertOk();

        $payment = DonationPayment::find($paymentId);
        $this->assertSame('succeeded', $payment->status);
        $this->assertTrue(MoneyMath::equals($payment->refunded_amount, '40.00'));
        $this->assertSame('60.00', (string) $seed['due']->fresh()->amount_paid);
        $this->assertSame('partially_paid', $seed['due']->fresh()->status);

        $second = $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '60.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'Remainder',
        ])->assertCreated()->json('data');

        $this->postJson('/api/tenant/donations/approvals/'.$second['approval_id'].'/decision', [
            'decision' => 'approved',
        ])->assertOk();

        $payment = $payment->fresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertTrue((bool) DonationReceipt::where('payment_id', $paymentId)->where('is_void', true)->exists());
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '1.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'extra',
        ])->assertStatus(422);
    }

    #[Test]
    public function rejected_refund_does_not_move_money(): void
    {
        $ctx = $this->collectUser();
        $seed = $this->seedDue($ctx['tenant']->id);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seed['family'], '1000.00', [
            'allocations' => [[
                'allocatable_type' => 'due',
                'allocatable_id' => $seed['due']->id,
                'amount' => '1000.00',
            ]],
        ]))->json('data.id');

        $refund = $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '10.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'No',
        ])->json('data');

        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'rejected',
        ])->assertOk();

        $this->assertSame('1000.00', (string) $seed['due']->fresh()->amount_paid);
        $this->assertSame('rejected', DonationRefund::find($refund['id'])->status);
        $this->assertSame('succeeded', DonationPayment::find($paymentId)->status);
    }

    #[Test]
    public function paid_donation_entry_cannot_be_silently_edited(): void
    {
        $ctx = $this->collectUser();
        $created = $this->postJson('/api/tenant/donations/entries/collect', [
            'title' => 'Offering',
            'donor_name' => 'Jane',
            'amount' => '50.00',
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertCreated();

        $donationId = $created->json('data.donation.id');
        $this->putJson("/api/tenant/donations/entries/{$donationId}", [
            'pledged_amount' => '1.00',
        ])->assertStatus(422);
    }
}
