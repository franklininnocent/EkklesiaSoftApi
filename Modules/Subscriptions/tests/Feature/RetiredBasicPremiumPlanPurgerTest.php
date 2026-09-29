<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Support\RetiredBasicPremiumPlanPurger;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RetiredBasicPremiumPlanPurgerTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function purge_removes_basic_and_premium_plans_completely(): void
    {
        $basic = Plan::query()->create([
            'key' => 'basic',
            'code' => 'LEGACY_BASIC',
            'slug' => 'legacy-basic',
            'name' => 'Basic Plan',
            'description' => 'legacy',
            'price' => 29.99,
            'max_users' => 50,
            'max_storage_mb' => 1000,
            'features' => ['donations'],
            'display_order' => 900,
            'active' => false,
            'is_default' => false,
            'pricing_type' => 'FIXED',
            'status' => 'ARCHIVED',
            'is_legacy' => true,
            'is_public' => false,
            'is_assignable' => false,
        ]);

        $tenant = Tenant::factory()->create(['plan' => 'basic']);

        app(RetiredBasicPremiumPlanPurger::class)->purge();

        $this->assertNull(Plan::withTrashed()->find($basic->id));
        $this->assertSame(0, Plan::withTrashed()->whereIn('key', ['basic', 'premium'])->count());
        $this->assertSame('starter', $tenant->fresh()->plan);
    }
}
