<?php

namespace Modules\Tenants\Tests\Unit;

use Carbon\Carbon;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SubscriptionService::class);
    }

    public function test_resolve_status_uses_configurable_grace_days(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        $settings = SubscriptionSettings::current();
        $settings->update([
            'grace_period_days' => 5,
            'expiring_warning_days' => 10,
        ]);

        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(3),
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_GRACE_PERIOD, $this->service->resolveStatus($tenant));

        $tenant->subscription_ends_at = Carbon::now()->subDays(6);
        $this->assertSame(SubscriptionService::STATUS_EXPIRED, $this->service->resolveStatus($tenant));
    }

    public function test_suspended_takes_priority_over_active_dates(): void
    {
        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->addYear(),
            'subscription_suspended_at' => Carbon::now(),
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_SUSPENDED, $this->service->resolveStatus($tenant));
        $this->assertSame(SubscriptionService::ACCESS_MODE_READ_ONLY, $this->service->accessMode($tenant));
        $this->assertFalse($this->service->isWriteAllowed($tenant));
        if ($this->service->usesReadOnlyWhenExpiredPolicy()) {
            $this->assertTrue($this->service->allowsGatedAccess($tenant));
        } else {
            $this->assertFalse($this->service->allowsGatedAccess($tenant));
        }
    }

    public function test_grace_period_allows_writes_under_read_only_policy(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        SubscriptionSettings::current()->update(['grace_period_days' => 7]);

        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(2),
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_GRACE_PERIOD, $this->service->resolveStatus($tenant));
        $this->assertTrue($this->service->isWriteAllowed($tenant));
        $this->assertSame(SubscriptionService::ACCESS_MODE_FULL, $this->service->accessMode($tenant));
    }

    public function test_expired_is_read_only_and_blocks_writes(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        SubscriptionSettings::current()->update(['grace_period_days' => 7]);

        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(10),
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_EXPIRED, $this->service->resolveStatus($tenant));
        $this->assertFalse($this->service->isWriteAllowed($tenant));
        $this->assertTrue($this->service->allowsGatedAccess($tenant));
        $this->assertSame(SubscriptionService::ACCESS_MODE_READ_ONLY, $this->service->accessMode($tenant));
    }

    public function test_build_access_snapshot_includes_access_mode(): void
    {
        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(10),
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $snapshot = $this->service->buildAccessSnapshot($tenant);

        $this->assertSame(SubscriptionService::ACCESS_MODE_READ_ONLY, $snapshot['access_mode']);
        $this->assertTrue($snapshot['is_read_only']);
    }

    public function test_expiring_status_within_warning_window(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        SubscriptionSettings::current()->update([
            'grace_period_days' => 7,
            'expiring_warning_days' => 14,
        ]);

        $tenant = new Tenant([
            'plan' => 'basic',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->addDays(5),
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_EXPIRING, $this->service->resolveStatus($tenant));
        $this->assertTrue($this->service->allowsGatedAccess($tenant));
    }

    public function test_lifetime_when_no_end_date(): void
    {
        $tenant = new Tenant([
            'plan' => 'enterprise',
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $this->assertSame(SubscriptionService::STATUS_LIFETIME, $this->service->resolveStatus($tenant));
        $this->assertTrue($this->service->allowsGatedAccess($tenant));
    }

    public function test_update_settings_persists_grace_days(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        $updated = $this->service->updateSettings([
            'grace_period_days' => 9,
            'expiring_warning_days' => 21,
        ], 1, 'SuperAdmin');

        $this->assertSame(9, (int) $updated->grace_period_days);
        $this->assertSame(21, (int) $updated->expiring_warning_days);
        $this->assertSame(9, (int) SubscriptionSettings::current()->grace_period_days);
    }

    public function test_audit_operation_labels_cover_known_operations(): void
    {
        $labels = $this->service->auditOperationLabels();

        $this->assertSame('Plan changed', $labels['plan_changed']);
        $this->assertSame('Subscription extended', $labels['subscription_extended']);
        $this->assertSame('Access suspended', $labels['subscription_suspended']);
        $this->assertSame('Access reactivated', $labels['subscription_reactivated']);
    }

    public function test_list_audits_for_tenant_returns_paginated_envelope(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenant_subscription_audits')) {
            $this->markTestSkipped('tenant_subscription_audits table not migrated');
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('tenants')) {
            $this->markTestSkipped('tenants table not available');
        }

        $tenant = Tenant::query()->create([
            'name' => 'Audit Test Church',
            'slug' => 'audit-test-church-' . uniqid(),
            'plan' => 'basic',
            'active' => 1,
            'max_users' => 10,
            'max_storage_mb' => 100,
            'features' => ['donations'],
        ]);

        \Modules\Tenants\Models\TenantSubscriptionAudit::query()->create([
            'tenant_id' => $tenant->id,
            'actor_id' => null,
            'actor_role' => 'SuperAdmin',
            'operation' => 'plan_changed',
            'source' => 'admin_ui',
            'reason' => 'Ops upgrade',
            'before_state' => ['plan' => 'free', 'status' => 'ACTIVE'],
            'after_state' => ['plan' => 'basic', 'status' => 'ACTIVE'],
            'correlation_id' => null,
            'created_at' => now(),
        ]);

        $result = $this->service->listAuditsForTenant((int) $tenant->id, ['per_page' => 10]);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertGreaterThanOrEqual(1, $result['pagination']['total']);
        $this->assertSame('plan_changed', $result['data'][0]['operation']);
        $this->assertSame('Plan changed', $result['data'][0]['operation_label']);
        $this->assertStringContainsString('free', $result['data'][0]['summary']);
        $this->assertStringContainsString('basic', $result['data'][0]['summary']);
    }

    public function test_default_plans_entitle_ministries_associations(): void
    {
        $this->assertArrayHasKey('ministries_associations', config('tenants.available_features'));

        foreach (array_keys(config('tenants.plans')) as $planKey) {
            $this->assertContains(
                'ministries_associations',
                config("tenants.plans.{$planKey}.features"),
                "Plan {$planKey} should include ministries_associations"
            );
        }
    }
}
