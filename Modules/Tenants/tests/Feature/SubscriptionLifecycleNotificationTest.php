<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SubscriptionLifecycleNotificationTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenants.subscription.lifecycle.mail_enabled' => true,
            'tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED,
        ]);

        if (\Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            SubscriptionSettings::current()->update(['grace_period_days' => 7, 'expiring_warning_days' => 14]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function lifecycle_worker_notifies_once_per_expired_transition(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'parish-admin@example.test',
        ]);

        Mail::shouldReceive('raw')->once();

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        Mail::shouldReceive('raw')->never();

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        $this->assertSame(1, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_expired')
            ->count());
    }

    #[Test]
    public function renew_triggers_subscription_extended_notice(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'renew-admin@example.test',
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(fn (string $body): bool => str_contains($body, 'renewed'));

        $this->asSuperAdmin();

        $this->postJson('/api/tenant/'.$tenant->id.'/subscription/renew', [
            'duration_months' => 12,
        ])->assertOk();
    }
}
