<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class AlignLaunchPlanFeaturesTest extends TestCase
{
    use ActsAsTenantRoles;
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function dry_run_does_not_create_versions(): void
    {
        $before = PlanVersion::query()->count();

        Artisan::call('subscriptions:align-launch-plan-features');

        $this->assertSame($before, PlanVersion::query()->count());
        $this->assertStringContainsString('Dry-run', Artisan::output());
    }

    #[Test]
    public function apply_publishes_new_version_when_active_matches_previous_seed(): void
    {
        $this->revertStandardToPreviousSeed();

        $starterVersionId = $this->plan('STARTER')->activeVersion->id;
        $before = PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count();

        Artisan::call('subscriptions:align-launch-plan-features', ['--apply' => true]);

        $after = PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count();
        $this->assertSame($before + 1, $after);
        $enabled = $this->enabledCodes($this->plan('STANDARD')->fresh()->activeVersion);
        $this->assertNotContains('ADVANCED_DASHBOARDS', $enabled);
        $this->assertSame($starterVersionId, $this->plan('STARTER')->fresh()->activeVersion->id);
    }

    #[Test]
    public function dry_run_reports_conflict_when_active_drift_is_custom(): void
    {
        $version = $this->plan('STANDARD')->activeVersion;
        $analyticsId = Feature::query()->where('code', 'ADVANCED_ANALYTICS')->value('id');
        $this->assertNotNull($analyticsId);
        DB::table('plan_entitlements')
            ->where('plan_version_id', $version->id)
            ->where('feature_id', $analyticsId)
            ->update(['is_enabled' => true]);

        Artisan::call('subscriptions:align-launch-plan-features');
        $output = Artisan::output();

        $this->assertStringContainsString('STANDARD: conflict', $output);
    }

    #[Test]
    public function force_code_can_align_a_conflicted_plan(): void
    {
        $version = $this->plan('STANDARD')->activeVersion;
        $analyticsId = Feature::query()->where('code', 'ADVANCED_ANALYTICS')->value('id');
        DB::table('plan_entitlements')
            ->where('plan_version_id', $version->id)
            ->where('feature_id', $analyticsId)
            ->update(['is_enabled' => true]);

        $before = PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count();
        Artisan::call('subscriptions:align-launch-plan-features', [
            '--apply' => true,
            '--force-code' => 'STANDARD',
        ]);
        $after = PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count();

        $this->assertSame($before + 1, $after);
        $this->assertNotContains('ADVANCED_ANALYTICS', $this->enabledCodes($this->plan('STANDARD')->fresh()->activeVersion));
    }

    #[Test]
    public function apply_does_not_change_pinned_tenant_plan_version_id(): void
    {
        $this->revertStandardToPreviousSeed();
        $ctx = $this->asTenantAdmin();
        $this->assignPlan($ctx['tenant'], 'STANDARD');
        $pinned = $this->currentSubscription($ctx['tenant'])->plan_version_id;

        Artisan::call('subscriptions:align-launch-plan-features', ['--apply' => true]);

        $this->assertSame($pinned, $this->currentSubscription($ctx['tenant']->fresh())->plan_version_id);
        $this->assertNotSame(
            $this->plan('STANDARD')->fresh()->activeVersion->id,
            $this->currentSubscription($ctx['tenant'])->plan_version_id
        );
    }

    #[Test]
    public function second_apply_is_idempotent(): void
    {
        $this->revertStandardToPreviousSeed();
        Artisan::call('subscriptions:align-launch-plan-features', ['--apply' => true]);
        $count = PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count();

        Artisan::call('subscriptions:align-launch-plan-features', ['--apply' => true]);

        $this->assertSame($count, PlanVersion::query()->where('plan_id', $this->plan('STANDARD')->id)->count());
    }

    private function revertStandardToPreviousSeed(): void
    {
        $version = $this->plan('STANDARD')->activeVersion;
        $dashboardId = Feature::query()->where('code', 'ADVANCED_DASHBOARDS')->value('id');
        $this->assertNotNull($dashboardId);

        DB::table('plan_entitlements')
            ->where('plan_version_id', $version->id)
            ->where('feature_id', $dashboardId)
            ->update(['is_enabled' => true]);
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
