<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\BillingPromotion;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class BillingPromotionApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function super_admin_can_create_and_activate_promotion(): void
    {
        $this->asSuperAdmin();

        $create = $this->postJson('/api/admin/subscriptions/billing/promotions', [
            'name' => 'Launch 10',
            'code' => 'LAUNCH10',
            'discount_type' => 'percent',
            'discount_value' => 10,
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->postJson("/api/admin/subscriptions/billing/promotions/{$id}/status", [
            'status' => 'active',
            'reason' => 'Go live',
        ])->assertOk();

        $this->assertSame(BillingPromotion::STATUS_ACTIVE, BillingPromotion::query()->findOrFail($id)->status);
    }
}
