<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsRbacMatrixTest extends DonationsCertificationTestCase
{
    #[Test]
    public function unauthenticated_requests_are_denied(): void
    {
        $this->getJson('/api/tenant/donations/payments')->assertStatus(401);
        $this->postJson('/api/tenant/donations/payments', [])->assertStatus(401);
    }

    #[Test]
    public function view_only_user_cannot_collect_or_reverse(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->getJson('/api/tenant/donations/payments')->assertOk();
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '10.00'))
            ->assertStatus(403);
    }

    #[Test]
    public function collect_cannot_reverse_or_refund(): void
    {
        $collector = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $collector['tenant']->id]);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '10.00'))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'nope'])
            ->assertStatus(403);
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '1.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'nope',
        ])->assertStatus(403);
        $this->getJson('/api/tenant/donations/approvals')->assertStatus(403);
    }

    #[Test]
    public function reverse_permission_can_reverse_but_not_refund(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.reverse']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '15.00'))
            ->json('data.id');
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'Correction'])
            ->assertOk();
        $this->assertSame('reversed', DonationPayment::find($paymentId)->status);
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/refunds", [
            'amount' => '1.00',
            'refund_date' => now()->toDateString(),
            'reason' => 'nope',
        ])->assertStatus(403);
    }
}
