<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\BillingInvoice;
use Modules\Subscriptions\Models\BillingOfflineClaim;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\Billing\OfflineReceiptAllocationService;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class OfflineClaimApprovalTest extends TestCase
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
    public function approver_cannot_be_claimant(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $invoice = $this->seedInvoice($tenant);

        $claim = BillingOfflineClaim::query()->create([
            'tenant_id' => $tenant->id,
            'submitted_by_user_id' => $user->id,
            'billing_invoice_id' => $invoice->id,
            'method_code' => 'bank_transfer',
            'claimed_amount' => '100.00',
            'currency_code' => 'INR',
            'status' => BillingOfflineClaim::STATUS_PENDING,
        ]);

        $service = app(OfflineReceiptAllocationService::class);
        $this->expectException(\Modules\Subscriptions\Exceptions\SubscriptionException::class);
        $service->approve($claim, $user);
    }

    #[Test]
    public function approval_allocates_and_marks_invoice_paid_when_full(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $claimant = User::factory()->create(['tenant_id' => $tenant->id]);
        $approver = User::factory()->create(['tenant_id' => null]);
        $invoice = $this->seedInvoice($tenant, '500.00');

        $claim = BillingOfflineClaim::query()->create([
            'tenant_id' => $tenant->id,
            'submitted_by_user_id' => $claimant->id,
            'billing_invoice_id' => $invoice->id,
            'method_code' => 'bank_transfer',
            'claimed_amount' => '500.00',
            'currency_code' => 'INR',
            'reference_number' => 'UTR123',
            'status' => BillingOfflineClaim::STATUS_PENDING,
        ]);

        $receipt = app(OfflineReceiptAllocationService::class)->approve($claim, $approver);
        $invoice->refresh();

        $this->assertSame(BillingInvoice::STATUS_PAID, $invoice->status);
        $this->assertSame('500.00', \App\Support\MoneyMath::normalize((string) $invoice->amount_paid));
        $this->assertSame(BillingOfflineClaim::STATUS_APPROVED, $claim->fresh()->status);
        $this->assertNotNull($receipt->id);
    }

    private function seedInvoice(Tenant $tenant, string $gross = '1499.00'): BillingInvoice
    {
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();

        $order = BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'test_'.uniqid(),
            'internal_reference' => 'T-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => $gross,
            'tax_amount' => '0.00',
            'gross_amount' => $gross,
            'amount_minor' => (int) bcmul($gross, '100', 0),
            'currency_code' => 'INR',
        ]);

        return BillingInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'billing_order_id' => $order->id,
            'invoice_number' => 'INV-'.uniqid(),
            'status' => BillingInvoice::STATUS_ISSUED,
            'net_amount' => $gross,
            'tax_amount' => '0.00',
            'gross_amount' => $gross,
            'amount_paid' => '0.00',
            'currency_code' => 'INR',
            'issued_at' => now(),
        ]);
    }
}
