<?php

namespace Modules\Tenants\Tests\Feature;

use App\Mail\TransactionalNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    #[Test]
    public function lifecycle_worker_notifies_once_per_expired_transition(): void
    {
        Mail::fake();

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

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        Mail::assertSent(TransactionalNotificationMail::class, 1);

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        Mail::assertSent(TransactionalNotificationMail::class, 1);

        $this->assertSame(1, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_expired')
            ->count());
    }

    #[Test]
    public function lifecycle_records_entered_view_only_when_enforcement_on(): void
    {
        Mail::fake();

        config(['tenants.subscription.view_only_locked_enforcement' => true]);
        SubscriptionSettings::current()->update([
            'grace_period_days' => 7,
            'view_only_allowance_days' => 90,
        ]);

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'view-only-lifecycle@example.test',
        ]);

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        $this->assertSame(1, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_view_only')
            ->count());
        $this->assertSame(0, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_expired')
            ->count());

        Mail::assertSent(TransactionalNotificationMail::class, 1);
    }

    #[Test]
    public function lifecycle_records_entered_locked_when_past_view_only_window(): void
    {
        Mail::fake();

        config(['tenants.subscription.view_only_locked_enforcement' => true]);
        SubscriptionSettings::current()->update([
            'grace_period_days' => 0,
            'view_only_allowance_days' => 0,
        ]);

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(3),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'locked-lifecycle@example.test',
        ]);

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();

        $this->assertSame(1, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_locked')
            ->count());
    }

    #[Test]
    public function renew_triggers_subscription_extended_notice(): void
    {
        Mail::fake();

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

        $this->asSuperAdmin();

        $this->postJson('/api/tenant/'.$tenant->id.'/subscription/renew', [
            'duration_months' => 12,
        ])->assertOk();

        Mail::assertSent(TransactionalNotificationMail::class, function (TransactionalNotificationMail $mail): bool {
            return str_contains($mail->render(), 'renewed');
        });
    }
}
