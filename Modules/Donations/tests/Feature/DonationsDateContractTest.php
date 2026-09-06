<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsDateContractTest extends DonationsCertificationTestCase
{
    #[Test]
    public function payment_date_is_stored_as_the_submitted_calendar_date(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $id = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '5.00', [
            'payment_date' => '2026-09-03',
        ]))->assertCreated()->json('data.id');

        $payment = DonationPayment::find($id);
        $this->assertSame('2026-09-03', $payment->payment_date->toDateString());
        $this->assertNotNull($payment->created_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $payment->created_at->toJSON());
    }
}
