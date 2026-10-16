<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Billing\BillingAction;
use Modules\Subscriptions\Billing\BillingAvailabilityPolicy;
use Modules\Subscriptions\Models\TenantBillingPolicy;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingAvailabilityPolicyTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function test_customer_payments_disabled_blocks_online_order(): void
    {
        $this->setBillingPolicy([
            'customer_payment_actions' => 'disabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);

        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $policy = app(BillingAvailabilityPolicy::class);
        $decision = $policy->decide($tenant, BillingAction::CreateOnlineOrder);

        $this->assertFalse($decision->allowed);
        $this->assertSame('customer_payments_disabled', $decision->reasonCode);
    }

    #[Test]
    public function test_online_disabled_while_offline_enabled(): void
    {
        $this->setBillingPolicy([
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'disabled',
            'offline_claims' => 'enabled',
        ]);

        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $policy = app(BillingAvailabilityPolicy::class);

        $this->assertFalse($policy->decide($tenant, BillingAction::CreateOnlineOrder)->allowed);
        $this->assertTrue($policy->decide($tenant, BillingAction::SubmitOfflineClaim)->allowed);
    }

    #[Test]
    public function test_tenant_deny_cannot_override_platform_allow(): void
    {
        $this->setBillingPolicy([
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);

        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        TenantBillingPolicy::query()->create([
            'tenant_id' => $tenant->id,
            'online_checkout' => 'allow',
            'offline_claims' => 'deny',
        ]);

        $policy = app(BillingAvailabilityPolicy::class);
        $this->assertFalse($policy->decide($tenant, BillingAction::SubmitOfflineClaim)->allowed);
    }

    #[Test]
    public function test_lifetime_tenant_cannot_quote(): void
    {
        $this->setBillingPolicy([
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);

        $tenant = Tenant::factory()->create([
            'subscription_ends_at' => null,
            'trial_ends_at' => null,
        ]);

        $policy = app(BillingAvailabilityPolicy::class);
        $this->assertFalse($policy->decide($tenant, BillingAction::QuotePurchase)->allowed);
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    private function setBillingPolicy(array $billing): void
    {
        $settings = SubscriptionSettings::current();
        $policies = is_array($settings->policies) ? $settings->policies : [];
        $policies['billing'] = array_replace($policies['billing'] ?? [], $billing);
        $settings->forceFill(['policies' => $policies])->save();
        app(\Modules\Subscriptions\Services\SubscriptionPolicyService::class)->forgetPoliciesMemo();
    }
}
