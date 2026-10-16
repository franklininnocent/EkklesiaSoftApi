<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingOrder;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Models\BillingPromotionRedemption;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingReleaseReservationsTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CatalogBootstrapper::class)->run();
    }

    #[Test]
    public function command_releases_reservation_for_expired_unpaid_order(): void
    {
        $tenant = Tenant::factory()->create(['subscription_ends_at' => now()->addMonth()]);
        $plan = $this->plan('STARTER');
        $version = $plan->activeVersion()->firstOrFail();
        $promo = BillingPromotion::query()->create([
            'code' => 'SAVE10',
            'name' => 'Save',
            'status' => BillingPromotion::STATUS_ACTIVE,
            'discount_type' => 'percent',
            'discount_value' => '10',
        ]);

        $order = BillingOrder::query()->create([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $version->id,
            'plan_id' => $plan->id,
            'billing_interval' => 'MONTHLY',
            'idempotency_key' => 'rel_'.uniqid(),
            'internal_reference' => 'BILL-'.uniqid(),
            'receipt' => 'r_'.uniqid(),
            'status' => BillingOrder::STATUS_AWAITING_PAYMENT,
            'net_amount' => '10.00',
            'tax_amount' => '0.00',
            'gross_amount' => '10.00',
            'amount_minor' => 1000,
            'currency_code' => 'USD',
            'expires_at' => now()->subMinute(),
        ]);

        BillingPromotionRedemption::query()->create([
            'billing_promotion_id' => $promo->id,
            'tenant_id' => $tenant->id,
            'billing_order_id' => $order->id,
            'status' => BillingPromotionRedemption::STATUS_RESERVED,
        ]);

        $this->artisan('billing:release-expired-reservations')->assertSuccessful();

        $this->assertSame(
            BillingPromotionRedemption::STATUS_RELEASED,
            BillingPromotionRedemption::query()->where('billing_order_id', $order->id)->value('status')
        );
    }
}
