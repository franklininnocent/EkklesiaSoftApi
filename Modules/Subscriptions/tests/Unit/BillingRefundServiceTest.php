<?php

namespace Modules\Subscriptions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPaymentAttempt;
use Modules\Subscriptions\Services\Billing\BillingRefundService;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingRefundServiceTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function approve_processes_razorpay_refund_and_marks_completed(): void
    {
        Http::fake([
            'api.razorpay.com/*' => Http::response(['id' => 'rfnd_test_1'], 200),
        ]);

        BillingGatewayConfig::query()->firstOrCreate(['environment' => 'test'], [
            'enabled' => true,
            'public_key_id' => 'rzp_test',
        ]);
        $config = BillingGatewayConfig::query()->where('environment', 'test')->firstOrFail();
        $config->public_key_id = 'rzp_test';
        $config->setApiSecretValue('secret_test');
        $config->save();

        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $order = BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'ref_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => BillingOrder::STATUS_CAPTURED,
            'net_amount' => '100',
            'tax_amount' => '0',
            'gross_amount' => '100',
            'amount_minor' => 10000,
            'currency_code' => 'INR',
            'environment' => 'test',
        ]);

        $attempt = BillingPaymentAttempt::query()->create([
            'billing_order_id' => $order->id,
            'provider_payment_id' => 'pay_test_1',
            'status' => BillingPaymentAttempt::STATUS_CAPTURED,
            'amount' => '100.00',
            'currency_code' => 'INR',
            'environment' => 'test',
        ]);

        $approver = User::factory()->create(['tenant_id' => null]);
        $service = app(BillingRefundService::class);
        $refund = $service->request($attempt, '50.00', 'Partial goodwill', $approver);
        $completed = $service->approveAndProcess($refund, $approver, 'Approved');

        $this->assertSame('completed', $completed->status);
        $this->assertSame('rfnd_test_1', $completed->provider_refund_id);
    }
}
