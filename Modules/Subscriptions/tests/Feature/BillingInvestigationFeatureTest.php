<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Modules\Subscriptions\Models\BillingGatewayConfig;
use Modules\Subscriptions\Models\BillingInvestigationItem;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class BillingInvestigationFeatureTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
        $config = BillingGatewayConfig::query()->where('environment', 'test')->firstOrFail();
        $config->public_key_id = 'rzp_test';
        $config->setApiSecretValue('api_secret_test');
        $config->enabled = true;
        $config->save();
        $this->enableBilling();
    }

    #[Test]
    public function amount_mismatch_opens_investigation(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->asTenantAdmin();
        $order = $this->seedOrder($tenant);
        Passport::actingAs($user);

        $paymentId = 'pay_mismatch';
        $signature = hash_hmac('sha256', $order->provider_order_id.'|'.$paymentId, 'api_secret_test');

        Http::fake([
            'api.razorpay.com/v1/payments/'.$paymentId => Http::response([
                'id' => $paymentId,
                'status' => 'captured',
                'amount' => 1,
                'currency' => $order->currency_code,
                'order_id' => $order->provider_order_id,
            ], 200),
        ]);

        $this->postJson("/api/tenant/billing/orders/{$order->id}/verify", [
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $signature,
        ])->assertStatus(422);

        $this->assertSame(BillingOrder::STATUS_NEEDS_INVESTIGATION, $order->fresh()->status);
        $this->assertTrue(
            BillingInvestigationItem::query()
                ->where('tenant_id', $tenant->id)
                ->where('category', 'amount_mismatch')
                ->where('status', BillingInvestigationItem::STATUS_OPEN)
                ->exists()
        );
    }

    private function seedOrder($tenant): BillingOrder
    {
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();

        return BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'inv_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'provider_order_id' => 'rzp_order_'.uniqid(),
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'environment' => 'test',
        ]);
    }

    private function enableBilling(): void
    {
        $settings = \Modules\Tenants\Models\SubscriptionSettings::query()->firstOrFail();
        $policies = is_array($settings->policies) ? $settings->policies : [];
        $policies['billing'] = array_replace((array) ($policies['billing'] ?? []), [
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);
        $settings->forceFill(['policies' => $policies])->save();
        app(\Modules\Subscriptions\Services\SubscriptionPolicyService::class)->forgetPoliciesMemo();
    }
}
