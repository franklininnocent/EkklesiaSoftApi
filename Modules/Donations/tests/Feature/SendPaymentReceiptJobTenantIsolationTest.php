<?php

namespace Modules\Donations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Donations\Jobs\SendPaymentReceiptJob;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\ReceiptDelivery;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SendPaymentReceiptJobTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function receipt_job_does_not_send_for_another_tenants_payment(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id, 'status' => 'active']);
        $paymentB = DonationPayment::create([
            'tenant_id' => $tenantB->id,
            'family_id' => $familyB->id,
            'payment_number' => 'PAY-'.substr(uniqid(), -10),
            'payer_name' => 'Victim Payer',
            'payer_email' => 'victim@example.com',
            'payment_date' => now()->toDateString(),
            'amount' => '25.00',
            'refunded_amount' => '0.00',
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        (new SendPaymentReceiptJob($tenantA->id, $paymentB->id))->handle(
            app(\Modules\Donations\Services\DonationNotificationService::class)
        );

        $this->assertSame(0, ReceiptDelivery::query()->where('tenant_id', $tenantA->id)->count());
        $this->assertSame(0, ReceiptDelivery::query()->where('tenant_id', $tenantB->id)->count());
    }
}
