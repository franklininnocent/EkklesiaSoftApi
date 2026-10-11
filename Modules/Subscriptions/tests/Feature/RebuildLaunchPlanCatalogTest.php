<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class RebuildLaunchPlanCatalogTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function dry_run_does_not_publish_or_prune(): void
    {
        $beforeVersions = PlanVersion::query()->count();

        Artisan::call('subscriptions:rebuild-launch-plan-catalog', ['--prune-unreferenced' => true]);
        $output = Artisan::output();

        $this->assertSame($beforeVersions, PlanVersion::query()->count());
        $this->assertStringContainsString('Dry-run', $output);
        $this->assertStringContainsString('STARTER', $output);
    }

    #[Test]
    public function apply_publishes_fresh_version_even_when_already_aligned(): void
    {
        $starterBefore = $this->plan('STARTER')->activeVersion->id;
        $countBefore = PlanVersion::query()->where('plan_id', $this->plan('STARTER')->id)->count();

        Artisan::call('subscriptions:rebuild-launch-plan-catalog', ['--apply' => true]);

        $starterAfter = $this->plan('STARTER')->fresh()->activeVersion;
        $this->assertNotSame($starterBefore, $starterAfter->id);
        $this->assertSame($countBefore + 1, PlanVersion::query()->where('plan_id', $this->plan('STARTER')->id)->count());
        $this->assertNotContains('ADVANCED_DASHBOARDS', $this->enabledCodes($starterAfter));
    }

    #[Test]
    public function prune_removes_unreferenced_retired_versions_but_keeps_pinned(): void
    {
        Artisan::call('subscriptions:rebuild-launch-plan-catalog', ['--apply' => true]);

        $retiredId = (int) PlanVersion::query()
            ->where('plan_id', $this->plan('STANDARD')->id)
            ->where('status', PlanVersion::STATUS_RETIRED)
            ->value('id');
        $this->assertGreaterThan(0, $retiredId);

        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $pinnedId = (int) $this->currentSubscription($ctx['tenant'])->plan_version_id;

        Artisan::call('subscriptions:rebuild-launch-plan-catalog', [
            '--apply' => true,
            '--prune-unreferenced' => true,
        ]);

        $this->assertDatabaseHas('plan_versions', ['id' => $pinnedId]);
        $this->assertDatabaseMissing('plan_versions', ['id' => $retiredId]);
        $this->assertNotSame($this->plan('STANDARD')->fresh()->activeVersion->id, $pinnedId);
    }

    #[Test]
    public function rebuild_sets_standard_matrix_without_advanced_dashboards(): void
    {
        $version = $this->plan('STANDARD')->activeVersion;
        $dashboardId = Feature::query()->where('code', 'ADVANCED_DASHBOARDS')->value('id');
        DB::table('plan_entitlements')
            ->where('plan_version_id', $version->id)
            ->where('feature_id', $dashboardId)
            ->update(['is_enabled' => true]);

        Artisan::call('subscriptions:rebuild-launch-plan-catalog', ['--apply' => true]);

        $enabled = $this->enabledCodes($this->plan('STANDARD')->fresh()->activeVersion);
        $this->assertNotContains('ADVANCED_DASHBOARDS', $enabled);
        $this->assertContains('ADVANCED_REPORTS', $enabled);
    }

    /**
     * @return list<string>
     */
    private function enabledCodes(PlanVersion $version): array
    {
        return PlanEntitlement::query()
            ->where('plan_version_id', $version->id)
            ->where('is_enabled', true)
            ->join('features', 'features.id', '=', 'plan_entitlements.feature_id')
            ->pluck('features.code')
            ->all();
    }
}
