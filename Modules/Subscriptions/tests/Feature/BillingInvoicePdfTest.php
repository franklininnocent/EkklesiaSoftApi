<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class BillingInvoicePdfTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function tenant_can_download_invoice_pdf(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->asTenantAdmin(
            Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()])
        );
        $invoice = $this->seedInvoice($tenant);
        Passport::actingAs($user);

        $response = $this->get('/api/tenant/billing/invoices/'.$invoice->id.'/download?format=pdf');
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
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
            'idempotency_key' => 'pdf_'.uniqid(),
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
