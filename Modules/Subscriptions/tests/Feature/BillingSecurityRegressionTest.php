<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Models\BillingOrder;
use Illuminate\Support\Facades\Http;
use Modules\Subscriptions\Services\Billing\BillingOrderService;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class BillingSecurityRegressionTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
        $this->enableBilling();
    }

    #[Test]
    public function tenant_cannot_view_another_tenants_order(): void
    {
        $tenantA = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $tenantB = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $orderB = $this->seedOrder($tenantB);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'active' => 1]);
        Passport::actingAs($userA);

        $this->getJson('/api/tenant/billing/orders/'.$orderB->id)->assertForbidden();
    }

    #[Test]
    public function duplicate_idempotency_key_returns_same_order_without_second_row(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->asTenantAdmin();
        $service = app(BillingOrderService::class);

        $this->mockRazorpayForOrder();

        $key = 'idem_'.uniqid();
        $first = $service->createOrder($tenant, $user, 'STARTER', 'MONTHLY', $key);
        $second = $service->createOrder($tenant, $user, 'STARTER', 'MONTHLY', $key);

        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertSame(1, BillingOrder::query()->where('idempotency_key', $key)->count());
    }

    #[Test]
    public function checkout_disabled_blocks_new_quote_but_not_history(): void
    {
        $this->setBillingPolicy(['customer_payment_actions' => 'enabled', 'online_checkout' => 'disabled', 'offline_claims' => 'disabled']);
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $policy = app(BillingAvailabilityPolicy::class);

        $this->assertFalse($policy->decide($tenant, BillingAction::CreateOnlineOrder)->allowed);
        $this->assertTrue($policy->decide($tenant, BillingAction::ViewBillingHistory)->allowed);
    }

    private function seedOrder(Tenant $tenant): BillingOrder
    {
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();

        return BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'sec_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'environment' => 'test',
        ]);
    }

    private function mockRazorpayForOrder(): void
    {
        $config = \Modules\Subscriptions\Models\BillingGatewayConfig::query()->where('environment', 'test')->firstOrFail();
        $config->public_key_id = 'rzp_test';
        $config->setApiSecretValue('secret');
        $config->enabled = true;
        $config->save();

        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'rzp_ord_test_1'], 200),
        ]);
    }

    private function enableBilling(): void
    {
        $this->setBillingPolicy([
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);
    }

    private function setBillingPolicy(array $billing): void
    {
        $settings = \Modules\Tenants\Models\SubscriptionSettings::query()->firstOrFail();
        $policies = is_array($settings->policies) ? $settings->policies : [];
        $policies['billing'] = array_replace((array) ($policies['billing'] ?? []), $billing);
        $settings->forceFill(['policies' => $policies])->save();
        app(\Modules\Subscriptions\Services\SubscriptionPolicyService::class)->forgetPoliciesMemo();
    }
}
