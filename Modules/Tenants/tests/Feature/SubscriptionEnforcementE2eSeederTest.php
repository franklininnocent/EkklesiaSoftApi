<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenants\Database\Seeders\SubscriptionEnforcementE2eSeeder;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionEnforcementE2eSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED,
            'tenants.subscription.view_only_locked_enforcement' => true,
        ]);
    }

    #[Test]
    public function seeder_produces_view_only_and_locked_access_modes_when_enforcement_on(): void
    {
        $this->seed(SubscriptionEnforcementE2eSeeder::class);

        $subscription = app(SubscriptionService::class);

        $viewOnly = Tenant::query()->where('slug', SubscriptionEnforcementE2eSeeder::VIEW_ONLY_SLUG)->firstOrFail();
        $locked = Tenant::query()->where('slug', SubscriptionEnforcementE2eSeeder::LOCKED_SLUG)->firstOrFail();

        $this->assertSame(
            SubscriptionService::ACCESS_MODE_VIEW_ONLY,
            $subscription->evaluateAccess($viewOnly)['access_mode']
        );
        $this->assertSame(
            SubscriptionService::ACCESS_MODE_LOCKED,
            $subscription->evaluateAccess($locked)['access_mode']
        );
    }
}
