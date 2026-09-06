<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsStateTransitionTest extends DonationsCertificationTestCase
{
    #[Test]
    public function illegal_payment_transitions_are_rejected(): void
    {
        $ctx = $this->actingAsTenantWith([
            'donations.view', 'donations.collect', 'donations.reverse', 'donations.refund', 'donations.approvals',
        ]);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00'))
            ->json('data.id');

        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'fix'])
            ->assertOk();
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '5.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'after reverse',
        ])->assertStatus(422);

        $secondId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00'))
            ->json('data.id');
        $refund = $this->postJson("/api/tenant/donations/payments/{$secondId}/refunds", [
            'amount' => '20.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'full',
        ])->json('data');
        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'approved',
        ])->assertOk();
        $this->assertSame('refunded', DonationPayment::find($secondId)->status);
        $this->postJson("/api/tenant/donations/payments/{$secondId}/reverse", ['reason' => 'nope'])
            ->assertStatus(422);
        $this->postJson('/api/tenant/donations/approvals/'.$refund['approval_id'].'/decision', [
            'decision' => 'rejected',
        ])->assertStatus(422);
    }
}
