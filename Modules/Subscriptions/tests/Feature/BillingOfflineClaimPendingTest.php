<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingOfflineClaimPendingTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function pending_claim_does_not_fulfill_order(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth(), 'plan' => 'starter']);
        $invoice = $this->seedInvoice($tenant);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        BillingOfflineClaim::query()->create([
            'tenant_id' => $tenant->id,
            'submitted_by_user_id' => $user->id,
            'billing_invoice_id' => $invoice->id,
            'billing_order_id' => $invoice->billing_order_id,
            'method_code' => 'bank_transfer',
            'claimed_amount' => (string) $invoice->gross_amount,
            'currency_code' => $invoice->currency_code,
            'status' => BillingOfflineClaim::STATUS_PENDING,
        ]);

        $order = BillingOrder::query()->findOrFail($invoice->billing_order_id);
        $this->assertNotSame(BillingOrder::STATUS_FULFILLED, $order->status);
    }

    private function seedInvoice(Tenant $tenant): BillingInvoice
    {
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $order = BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'pend_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
        ]);

        return BillingInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'billing_order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'status' => BillingInvoice::STATUS_ISSUED,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_paid' => '0.00',
            'currency_code' => 'USD',
            'issued_at' => now(),
        ]);
    }
}
