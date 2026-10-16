<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingTenantPriceAssignment;
use Modules\Subscriptions\Services\Billing\BillingQuoteService;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingNegotiatedPriceTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
        $this->setBillingPolicy([
            'customer_payment_actions' => 'enabled',
            'online_checkout' => 'enabled',
            'offline_claims' => 'enabled',
        ]);
    }

    #[Test]
    public function negotiated_assignment_reduces_quote_net_before_tax(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $plan = $this->plan('STARTER');

        BillingTenantPriceAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'negotiated_amount' => '25.00',
            'currency_code' => 'USD',
            'active' => true,
            'reason' => 'Test deal',
        ]);

        $quote = app(BillingQuoteService::class)->quote($tenant, 'STARTER', 'MONTHLY');

        $this->assertSame('25.00', $quote['net']);
        $this->assertNotNull($quote['discount']);
        $this->assertSame('negotiated', $quote['discount']['type']);
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
