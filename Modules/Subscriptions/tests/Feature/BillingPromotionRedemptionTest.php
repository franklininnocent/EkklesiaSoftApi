<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Models\BillingPromotionRedemption;
use Modules\Subscriptions\Services\Billing\BillingPromotionRedemptionService;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingPromotionRedemptionTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function exhausted_promotion_blocks_reservation(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $promo = BillingPromotion::query()->create([
            'code' => 'ONCE',
            'name' => 'Once',
            'status' => BillingPromotion::STATUS_ACTIVE,
            'discount_type' => 'percent',
            'discount_value' => '10',
            'max_redemptions' => 1,
            'redemption_count' => 1,
        ]);

        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $order = \Modules\Subscriptions\Models\BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'promo_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => \Modules\Subscriptions\Models\BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10',
            'tax_amount' => '0',
            'gross_amount' => '10',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'commercial_snapshot' => [
                'discount' => ['type' => 'promotion', 'promotion_id' => $promo->id],
            ],
        ]);

        $this->expectException(\Modules\Subscriptions\Exceptions\SubscriptionException::class);
        $quote = ['discount' => ['type' => 'promotion', 'promotion_id' => $promo->id]];
        app(BillingPromotionRedemptionService::class)->reserveForOrder($tenant, $quote, $order);
    }

    #[Test]
    public function fulfillment_consumes_reserved_redemption(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $promo = BillingPromotion::query()->create([
            'code' => 'OK',
            'name' => 'OK',
            'status' => BillingPromotion::STATUS_ACTIVE,
            'discount_type' => 'percent',
            'discount_value' => '5',
            'max_redemptions' => 5,
            'redemption_count' => 0,
        ]);

        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $order = \Modules\Subscriptions\Models\BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'promo2_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => \Modules\Subscriptions\Models\BillingOrder::STATUS_CAPTURED,
            'net_amount' => '10',
            'tax_amount' => '0',
            'gross_amount' => '10',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'commercial_snapshot' => [
                'discount' => ['type' => 'promotion', 'promotion_id' => $promo->id],
            ],
        ]);

        BillingPromotionRedemption::query()->create([
            'billing_promotion_id' => $promo->id,
            'tenant_id' => $tenant->id,
            'billing_order_id' => $order->id,
            'status' => BillingPromotionRedemption::STATUS_RESERVED,
        ]);

        app(BillingPromotionRedemptionService::class)->consumeForOrder($order);

        $this->assertSame(1, (int) $promo->fresh()->redemption_count);
        $this->assertSame(BillingPromotionRedemption::STATUS_CONSUMED, BillingPromotionRedemption::query()->where('billing_order_id', $order->id)->value('status'));
    }
}
