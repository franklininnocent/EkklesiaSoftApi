<?php

namespace Modules\Donations\Tests\Feature;

use Modules\Donations\Models\DonationReceipt;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use Modules\Family\Models\Family;
use PHPUnit\Framework\Attributes\Test;

class DonationsReceiptLifecycleTest extends DonationsCertificationTestCase
{
    #[Test]
    public function receipt_snapshot_is_frozen_and_void_reissue_creates_a_new_number(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect', 'donations.manage']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $payment = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '88.00', [
            'payer_name' => 'Original Name',
        ]))->assertCreated()->json('data');

        $original = DonationReceipt::where('payment_id', $payment['id'])->first();
        $this->assertNotEmpty($original->snapshot);
        $this->assertSame(88.0, (float) $original->snapshot['totals']['amount']);

        $html = $this->get('/api/tenant/donations/payments/'.$payment['id'].'/receipt/print');
        $html->assertOk();
        $this->assertStringContainsString('Original Name', $html->getContent());
        $this->assertStringNotContainsString('<script>', $html->getContent());

        $reissue = $this->postJson('/api/tenant/donations/receipts/'.$original->id.'/reissue', [
            'reason' => 'Wrong printed name',
        ])->assertCreated();

        $original->refresh();
        $this->assertTrue((bool) $original->is_void);
        $replacement = DonationReceipt::find($reissue->json('data.id'));
        $this->assertNotSame($original->receipt_number, $replacement->receipt_number);
        $this->assertSame($original->id, $replacement->replaces_receipt_id);
        $this->assertFalse((bool) $replacement->is_void);

        $voidHtml = $this->get('/api/tenant/donations/receipts/'.$original->id);
        $voidHtml->assertOk()->assertJsonPath('data.void.is_void', true);
    }

    #[Test]
    public function xss_payloads_are_escaped_in_receipt_html(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $payment = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '9.00', [
            'payer_name' => '<script>alert(1)</script>',
            'notes' => '<img src=x onerror=alert(1)>',
        ]))->assertCreated()->json('data');

        $html = $this->get('/api/tenant/donations/payments/'.$payment['id'].'/receipt/print')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    #[Test]
    public function receipt_numbers_are_unique_for_sequential_issues(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view', 'donations.collect']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $id = $this->postJson('/api/tenant/donations/payments', $this->paymentPayload($family, '1.00'))
                ->assertCreated()
                ->json('data.id');
            $numbers[] = DonationReceipt::where('payment_id', $id)->value('receipt_number');
        }
        $this->assertCount(5, array_unique($numbers));
    }
}
