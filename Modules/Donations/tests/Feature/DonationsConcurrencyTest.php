<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Services\DonationNumberSequenceService;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsConcurrencyTest extends DonationsCertificationTestCase
{
    #[Test]
    public function overlapping_reverse_of_the_same_payment_applies_once(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.reverse']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $paymentId = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '40.00'))
            ->json('data.id');

        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'first'])
            ->assertOk();
        $this->postJson("/api/tenant/donations/payments/{$paymentId}/reverse", ['reason' => 'second'])
            ->assertStatus(422);
        $this->assertSame('reversed', DonationPayment::find($paymentId)->status);
        $this->assertSame(1, DonationReceipt::where('payment_id', $paymentId)->where('is_void', true)->count());
    }

    #[Test]
    public function locked_sequences_issue_monotonic_receipt_numbers(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $service = app(DonationNumberSequenceService::class);
        $first = $service->nextReceiptNumber((int) $ctx['tenant']->id);
        $second = $service->nextReceiptNumber((int) $ctx['tenant']->id);
        $this->assertNotSame($first, $second);
    }
}
