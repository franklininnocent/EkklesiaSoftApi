<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Database\Seeders\SubscriptionExpiredE2eSeeder;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionExpiredE2eSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        if (\Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            SubscriptionSettings::current()->update(['grace_period_days' => 7, 'expiring_warning_days' => 14]);
        }
    }

    #[Test]
    public function seeder_creates_isolated_expired_and_grace_tenants_idempotently(): void
    {
        $this->seed(SubscriptionExpiredE2eSeeder::class);
        $this->seed(SubscriptionExpiredE2eSeeder::class);

        $subscription = app(SubscriptionService::class);

        $expired = Tenant::query()->where('slug', SubscriptionExpiredE2eSeeder::EXPIRED_SLUG)->first();
        $grace = Tenant::query()->where('slug', SubscriptionExpiredE2eSeeder::GRACE_SLUG)->first();

        $this->assertNotNull($expired);
        $this->assertNotNull($grace);
        $this->assertSame(1, Tenant::query()->where('slug', SubscriptionExpiredE2eSeeder::EXPIRED_SLUG)->count());
        $this->assertSame(1, Tenant::query()->where('slug', SubscriptionExpiredE2eSeeder::GRACE_SLUG)->count());

        $this->assertSame(SubscriptionService::STATUS_EXPIRED, $subscription->resolveStatus($expired));
        $this->assertSame(SubscriptionService::ACCESS_MODE_READ_ONLY, $subscription->accessMode($expired));

        $this->assertSame(SubscriptionService::STATUS_GRACE_PERIOD, $subscription->resolveStatus($grace));
        $this->assertSame(SubscriptionService::ACCESS_MODE_FULL, $subscription->accessMode($grace));

        $this->assertNotContains('ministries_associations', $expired->features ?? []);
    }

    #[Test]
    public function seeder_restores_soft_deleted_fixture_member_on_reseed(): void
    {
        $this->seed(SubscriptionExpiredE2eSeeder::class);

        FamilyMember::withoutTenantScope()
            ->where('id', SubscriptionExpiredE2eSeeder::EXPIRED_MEMBER_ID)
            ->delete();

        $this->assertSoftDeleted('family_members', [
            'id' => SubscriptionExpiredE2eSeeder::EXPIRED_MEMBER_ID,
        ]);

        $this->seed(SubscriptionExpiredE2eSeeder::class);

        $this->assertDatabaseHas('family_members', [
            'id' => SubscriptionExpiredE2eSeeder::EXPIRED_MEMBER_ID,
            'deleted_at' => null,
        ]);
    }
}
