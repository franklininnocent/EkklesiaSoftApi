<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Catalog\EntitlementParityChecker;
use Modules\Subscriptions\Services\Catalog\TenantSubscriptionBackfiller;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillAndParityTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function backfill_grandfathers_every_tenant_with_zero_entitlement_changes(): void
    {
        $tenants = [
            Tenant::factory()->create(['plan' => 'free', 'features' => ['donations']]),
            Tenant::factory()->create(['plan' => 'starter', 'features' => []]),
            Tenant::factory()->create(['plan' => 'standard', 'features' => ['events', 'messaging']]),
            Tenant::factory()->create(['plan' => 'enterprise', 'features' => ['api_access', 'donations', 'ministries_associations']]),
            Tenant::factory()->create(['plan' => 'unknown-plan', 'features' => ['groups']]),
        ];

        $legacyDecisions = [];
        foreach ($tenants as $tenant) {
            foreach (['donations', 'ministries_associations', 'events', 'messaging', 'api_access', 'groups'] as $key) {
                $legacyDecisions[$tenant->id][$key] = $tenant->fresh()->legacyFeatureDecision($key);
            }
        }

        $stats = app(TenantSubscriptionBackfiller::class)->run();

        $this->assertSame(count($tenants), $stats['tenants_backfilled']);
        $this->assertContains($tenants[4]->id, $stats['fallback_plan_tenants']);

        $report = app(EntitlementParityChecker::class)->check();
        $this->assertSame([], $report['mismatches'] ?? [], 'Backfill must not change any tenant entitlement');

        $this->useEntitlementEngine('enforce');
        foreach ($tenants as $tenant) {
            $fresh = $tenant->fresh();
            $this->assertNotNull($this->currentSubscription($fresh));
            foreach ($legacyDecisions[$tenant->id] as $key => $expected) {
                $this->assertSame($expected, $fresh->hasFeature($key), "tenant {$tenant->id} {$key}");
            }
        }
    }

    #[Test]
    public function backfill_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create(['plan' => 'free', 'features' => ['donations']]);

        app(TenantSubscriptionBackfiller::class)->run();
        $overrides = TenantEntitlementOverride::query()->where('tenant_id', $tenant->id)->count();
        app(TenantSubscriptionBackfiller::class)->run();

        $this->assertSame(1, TenantSubscription::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($overrides, TenantEntitlementOverride::query()->where('tenant_id', $tenant->id)->count());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        Tenant::factory()->create(['plan' => 'starter']);

        $stats = app(TenantSubscriptionBackfiller::class)->run(true);

        $this->assertSame(1, $stats['tenants_backfilled']);
        $this->assertSame(0, TenantSubscription::query()->count());
    }

    #[Test]
    public function parity_command_passes_after_backfill(): void
    {
        Tenant::factory()->create(['plan' => 'standard', 'features' => ['messaging']]);

        $this->artisan('subscriptions:backfill')->assertSuccessful();
        $this->artisan('subscriptions:verify-entitlement-parity')->assertSuccessful();
    }
}
