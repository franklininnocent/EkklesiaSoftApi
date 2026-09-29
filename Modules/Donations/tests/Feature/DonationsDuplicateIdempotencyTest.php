<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsDuplicateIdempotencyTest extends DonationsCertificationTestCase
{
    #[Test]
    public function same_idempotency_key_returns_the_original_payment(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $payload = $this->paymentPayload($family, '33.00');
        $headers = ['Idempotency-Key' => 'qc-click-1'];

        $first = $this->postJson('/api/tenant/donations/payments', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/tenant/donations/payments', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, DonationPayment::query()->count());
        $this->assertSame(1, DonationReceipt::query()->count());
    }

    #[Test]
    public function same_key_different_payload_conflicts(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $headers = ['Idempotency-Key' => 'qc-click-2'];
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '10.00'), $headers)->assertCreated();
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '11.00'), $headers)->assertStatus(409);
        $this->assertSame(1, DonationPayment::query()->count());
    }

    #[Test]
    public function duplicate_gateway_reference_is_rejected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00', [
            'method' => 'cheque',
            'gateway_reference' => 'CHQ-9911',
        ]))->assertCreated();
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00', [
            'method' => 'cheque',
            'gateway_reference' => 'CHQ-9911',
        ]))->assertStatus(422);
        $this->assertSame(1, DonationPayment::query()->count());
    }

    #[Test]
    public function cheque_without_reference_is_rejected(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '20.00', [
            'method' => 'cheque',
        ]))->assertStatus(422);
    }
}
