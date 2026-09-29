<?php

namespace Modules\Subscriptions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanEntitlement;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Catalog\CatalogBootstrapper;
use Modules\Subscriptions\Services\SubscriptionPolicyService;
use Modules\Subscriptions\Tests\Concerns\InteractsWithSubscriptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogBootstrapTest extends TestCase
{
    use InteractsWithSubscriptions;
    use RefreshDatabase;

    #[Test]
    public function launch_plans_are_seeded_with_active_versions_and_prices(): void
    {
        $expected = [
            'STARTER' => ['1499.00', '14990.00', 250],
            'STANDARD' => ['2999.00', '29990.00', 1000],
            'PROFESSIONAL' => ['4999.00', '49990.00', 2500],
        ];

        foreach ($expected as $code => [$monthly, $annual, $people]) {
            $plan = $this->plan($code);
            $version = $plan->activeVersion;

            $this->assertSame(Plan::STATUS_ACTIVE, $plan->status, $code);
            $this->assertTrue((bool) $plan->is_public, $code);
            $this->assertNotNull($version, $code);
            $this->assertSame($monthly, (string) $version->monthly_price, $code);
            $this->assertSame($annual, (string) $version->annual_price, $code);
            $this->assertSame($people, $this->limitOf($version, 'PEOPLE_LIMIT'), $code);
        }

        $enterprise = $this->plan('ENTERPRISE');
        $this->assertSame(Plan::PRICING_CUSTOM, $enterprise->pricing_type);
        $this->assertNull($enterprise->activeVersion->monthly_price);
        $this->assertTrue((bool) $this->plan('STANDARD')->is_featured);
        $this->assertSame('Most Popular', $this->plan('STANDARD')->badge_label);
    }

    #[Test]
    public function plan_feature_sets_are_cumulative(): void
    {
        $order = ['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'];
        $previous = [];

        foreach ($order as $code) {
            $enabled = $this->enabledCodes($this->plan($code)->activeVersion);
            $this->assertSame([], array_values(array_diff($previous, $enabled)), "{$code} must include every lower-tier feature");
            $previous = $enabled;
        }
    }

    #[Test]
    public function core_features_are_enabled_on_every_plan(): void
    {
        $core = Feature::query()->where('is_core', true)->pluck('code')->all();
        $this->assertNotEmpty($core);

        foreach (['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'] as $code) {
            $enabled = $this->enabledCodes($this->plan($code)->activeVersion);
            $this->assertSame([], array_values(array_diff($core, $enabled)), $code);
        }
    }

    #[Test]
    public function legacy_plans_are_hidden_and_not_assignable(): void
    {
        foreach (['LEGACY_FREE'] as $code) {
            $plan = $this->plan($code);
            $this->assertTrue((bool) $plan->is_legacy, $code);
            $this->assertFalse((bool) $plan->is_public, $code);
            $this->assertFalse((bool) $plan->is_assignable, $code);
        }
    }

    #[Test]
    public function default_plan_policy_points_to_starter(): void
    {
        $this->assertSame($this->plan('STARTER')->id, app(SubscriptionPolicyService::class)->defaultPlanId());
    }

    #[Test]
    public function mass_intentions_is_catalogued_and_off_on_every_launch_plan(): void
    {
        $feature = Feature::query()->where('code', 'MASS_INTENTIONS')->first();
        $this->assertNotNull($feature);
        $this->assertSame('mass_intentions', $feature->legacy_key);
        $this->assertTrue((bool) $feature->is_active);
        $this->assertFalse((bool) $feature->is_core);

        foreach (['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'] as $code) {
            $this->assertNotContains('MASS_INTENTIONS', $this->enabledCodes($this->plan($code)->activeVersion), $code);
            $row = PlanEntitlement::query()
                ->where('plan_version_id', $this->plan($code)->activeVersion->id)
                ->where('feature_id', $feature->id)
                ->first();
            $this->assertNotNull($row, $code);
            $this->assertFalse((bool) $row->is_enabled, $code);
        }
    }

    #[Test]
    public function bootstrapper_restores_a_missing_mass_intentions_row_without_enabling_it(): void
    {
        $featureId = Feature::query()->where('code', 'MASS_INTENTIONS')->value('id');
        $this->assertNotNull($featureId);
        \Illuminate\Support\Facades\DB::table('plan_entitlements')->where('feature_id', $featureId)->delete();
        Feature::query()->whereKey($featureId)->delete();

        $result = app(CatalogBootstrapper::class)->run();

        $this->assertSame(1, $result['features_created']);
        $restored = Feature::query()->where('code', 'MASS_INTENTIONS')->first();
        $this->assertNotNull($restored);
        $this->assertGreaterThan(0, $result['entitlements_attached']);
        foreach (['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'] as $code) {
            $this->assertNotContains('MASS_INTENTIONS', $this->enabledCodes($this->plan($code)->activeVersion), $code);
        }
    }

    #[Test]
    public function bootstrapper_is_idempotent(): void
    {
        $counts = fn () => [Plan::withTrashed()->count(), PlanVersion::count(), Feature::count(), PlanEntitlement::count()];
        $before = $counts();

        app(CatalogBootstrapper::class)->run();
        app(CatalogBootstrapper::class)->run();

        $this->assertSame($before, $counts());
    }

    private function limitOf(PlanVersion $version, string $code): ?int
    {
        $featureId = Feature::query()->where('code', $code)->value('id');
        $value = PlanEntitlement::query()->where('plan_version_id', $version->id)->where('feature_id', $featureId)->value('numeric_value');

        return $value === null ? null : (int) $value;
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
