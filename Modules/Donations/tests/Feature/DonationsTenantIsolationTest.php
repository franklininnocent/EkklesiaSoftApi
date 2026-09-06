<?php

namespace Modules\Donations\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationSecurityEvent;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsTenantIsolationTest extends DonationsCertificationTestCase
{
    #[Test]
    public function tenant_a_cannot_read_or_mutate_tenant_b_financial_records(): void
    {
        $a = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.reverse', 'donations.refund', 'donations.manage']);
        $seedA = $this->seedDue($a['tenant']->id);
        $paymentA = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seedA['family'], '25.00'))
            ->assertCreated()
            ->json('data');

        $b = $this->makeTenantUser(['donations.view', 'donations.collect', 'donations.reverse', 'donations.refund', 'donations.manage']);
        Passport::actingAs($b['user']);
        $seedB = $this->seedDue($b['tenant']->id);
        $paymentB = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seedB['family'], '80.00'))
            ->assertCreated()
            ->json('data');

        Passport::actingAs($a['user']);

        $this->getJson('/api/tenant/donations/payments?per_page=50')
            ->assertOk();
        $ids = collect($this->getJson('/api/tenant/donations/payments?per_page=50')->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($paymentA['id']));
        $this->assertFalse($ids->contains($paymentB['id']));
        $this->assertSame(1, (int) $this->getJson('/api/tenant/donations/payments?per_page=50')->json('data.total'));

        $this->getJson('/api/tenant/donations/payments/'.$paymentB['id'].'/receipt')->assertNotFound();
        $this->postJson('/api/tenant/donations/payments/'.$paymentB['id'].'/reverse', ['reason' => 'steal'])
            ->assertNotFound();
        $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($seedB['family'], '10.00'))
            ->assertStatus(422);
        $this->assertGreaterThan(0, DonationSecurityEvent::query()->count());
        $this->assertSame(0, DonationPayment::query()->where('tenant_id', $a['tenant']->id)->where('id', $paymentB['id'])->count());
    }

    #[Test]
    public function client_cannot_mass_assign_tenant_or_payment_status(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $other = $this->makeTenantUser(['donations.view']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $response = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '12.00', [
            'tenant_id' => $other['tenant']->id,
            'status' => 'reversed',
            'receipt_number' => 'HACK-1',
            'payment_number' => 'HACK-PAY',
        ]));
        $response->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $payment = DonationPayment::find($response->json('data.id'));
        $this->assertSame($ctx['tenant']->id, (int) $payment->tenant_id);
        $this->assertSame('succeeded', $payment->status);
        $this->assertStringStartsWith('PAY-', $payment->payment_number);
        $this->assertNotSame('HACK-PAY', $payment->payment_number);
    }
}
