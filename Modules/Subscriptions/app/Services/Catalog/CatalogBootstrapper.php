<?php

namespace Modules\Subscriptions\Services\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;
use Modules\Tenants\Models\SubscriptionSettings;

/**
 * Idempotent first-install seeding of the feature catalog, launch plans and policies.
 * Insert-if-missing only: existing rows (Super Admin edits) are never overwritten.
 */
class CatalogBootstrapper
{
    public function __construct(
        private readonly EntitlementCatalog $catalog,
        private readonly PlanVersionContentHasher $hasher,
        private readonly LegacyPlanConverter $legacyConverter,
    ) {}

    /**
     * Order matters: features first, then legacy rows are grandfathered (so "enterprise" is
     * adopted rather than duplicated), then launch plans and policies.
     *
     * @return array{features_created: int, dependencies_created: int, entitlements_attached: int, legacy_converted: list<string>, plans_created: list<string>}
     */
    public function run(): array
    {
        return DB::transaction(function (): array {
            $featuresCreated = $this->syncFeatures();
            $dependenciesCreated = $this->syncDependencies();
            $this->catalog->flush();
            $legacyConverted = $this->legacyConverter->convert();
            $plansCreated = $this->syncLaunchPlans();
            $entitlementsAttached = $this->attachMissingPlanEntitlements();
            $this->syncPolicies();
            $this->catalog->flush();

            return [
                'features_created' => $featuresCreated,
                'dependencies_created' => $dependenciesCreated,
                'entitlements_attached' => $entitlementsAttached,
                'legacy_converted' => $legacyConverted,
                'plans_created' => $plansCreated,
            ];
        });
    }

    public function syncFeatures(): int
    {
        $created = 0;
        foreach (SubscriptionCatalogDefinition::features() as $definition) {
            if (Feature::query()->where('code', $definition['code'])->exists()) {
                continue;
            }
            if ($definition['legacy_key'] && Feature::query()->where('legacy_key', $definition['legacy_key'])->exists()) {
                $definition['legacy_key'] = null;
            }
            Feature::query()->create($definition + ['is_active' => true]);
            $created++;
        }

        return $created;
    }

    /**
     * Additive only: boolean/module/tier catalog rows that appeared after a plan version
     * was published get an explicit off entitlement. Never enables a feature and never
     * invents limit/quota values. Uses the query builder because published versions
     * reject Eloquent entitlement writes.
     */
    public function attachMissingPlanEntitlements(): int
    {
        $attached = 0;
        $versionIds = PlanVersion::query()->pluck('id');
        if ($versionIds->isEmpty()) {
            return 0;
        }

        $now = now();
        foreach (Feature::query()->get() as $feature) {
            if ($feature->isNumeric()) {
                continue;
            }

            $existing = DB::table('plan_entitlements')
                ->where('feature_id', $feature->id)
                ->pluck('plan_version_id');
            $missing = $versionIds->diff($existing);
            if ($missing->isEmpty()) {
                continue;
            }

            $rows = [];
            foreach ($missing as $versionId) {
                $rows[] = [
                    'plan_version_id' => $versionId,
                    'feature_id' => $feature->id,
                    'is_enabled' => (bool) $feature->is_core,
                    'numeric_value' => null,
                    'tier_value' => null,
                    'config' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('plan_entitlements')->insert($rows);
            $attached += count($rows);
        }

        return $attached;
    }

    public function syncDependencies(): int
    {
        $ids = Feature::query()->pluck('id', 'code');
        $created = 0;
        foreach (SubscriptionCatalogDefinition::dependencies() as $code => $requires) {
            foreach ($requires as $required) {
                if (! isset($ids[$code], $ids[$required])) {
                    continue;
                }
                $exists = DB::table('feature_dependencies')
                    ->where('feature_id', $ids[$code])
                    ->where('depends_on_feature_id', $ids[$required])
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('feature_dependencies')->insert([
                    'feature_id' => $ids[$code],
                    'depends_on_feature_id' => $ids[$required],
                    'dependency_type' => 'REQUIRES',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    public function syncLaunchPlans(): array
    {
        $created = [];
        $features = Feature::query()->get()->keyBy('code');

        foreach (SubscriptionCatalogDefinition::plans() as $definition) {
            $plan = Plan::withTrashed()->where('code', $definition['code'])->first();
            if ($plan && PlanVersion::query()->where('plan_id', $plan->id)->exists()) {
                // Legacy "enterprise" row adopted by LegacyPlanConverter may only have its v1.
                if ($definition['code'] === 'ENTERPRISE') {
                    $this->ensureEnterpriseCustomVersion($plan, $definition, $features);
                }

                continue;
            }

            if (! $plan) {
                $keyTaken = Plan::withTrashed()->where('key', $definition['key'])->exists();
                $plan = Plan::query()->create([
                    'code' => $definition['code'],
                    'key' => $keyTaken ? strtolower($definition['code']).'_'.Str::lower(Str::random(4)) : $definition['key'],
                    'slug' => Str::slug($definition['name']),
                    'name' => $definition['name'],
                    'description' => $definition['short_description'],
                    'short_description' => $definition['short_description'],
                    'pricing_type' => $definition['pricing_type'],
                    'status' => Plan::STATUS_ACTIVE,
                    'is_public' => true,
                    'is_featured' => $definition['is_featured'],
                    'is_assignable' => true,
                    'is_legacy' => false,
                    'badge_label' => $definition['badge_label'],
                    'display_order' => $definition['display_order'],
                    'price' => $definition['version']['monthly_price'] ?? 0,
                    'active' => true,
                    'is_default' => false,
                ]);
            }

            $this->createVersion($plan, 1, PlanVersion::STATUS_ACTIVE, $definition, $features, 'Launch version');
            $this->mirrorLegacyColumns($plan, $definition);
            $created[] = $definition['code'];
        }

        return $created;
    }

    public function syncPolicies(): void
    {
        $settings = SubscriptionSettings::current();
        $changes = [];

        if (! $settings->getAttribute('policies')) {
            $changes['policies'] = SubscriptionCatalogDefinition::defaultPolicies();
        }
        if (! $settings->getAttribute('default_plan_id')) {
            $starter = Plan::query()->where('code', SubscriptionCatalogDefinition::DEFAULT_PLAN_CODE)->first();
            if ($starter) {
                $changes['default_plan_id'] = $starter->id;
            }
        }

        if ($changes !== []) {
            $settings->forceFill($changes)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  Collection<string, Feature>  $features
     */
    private function ensureEnterpriseCustomVersion(Plan $plan, array $definition, $features): void
    {
        $hasCurrentTerms = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', [PlanVersion::STATUS_ACTIVE, PlanVersion::STATUS_SCHEDULED, PlanVersion::STATUS_DRAFT])
            ->exists();
        if ($hasCurrentTerms) {
            return;
        }

        $next = (int) PlanVersion::query()->where('plan_id', $plan->id)->max('version_number') + 1;
        $this->createVersion($plan, $next, PlanVersion::STATUS_ACTIVE, $definition, $features, 'Enterprise custom pricing');
        $plan->forceFill([
            'pricing_type' => Plan::PRICING_CUSTOM,
            'is_public' => true,
            'is_assignable' => true,
            'is_legacy' => false,
            'status' => Plan::STATUS_ACTIVE,
            'short_description' => $plan->short_description ?: $definition['short_description'],
            'display_order' => $definition['display_order'],
        ])->save();
        $this->mirrorLegacyColumns($plan, $definition);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  Collection<string, Feature>  $features
     */
    private function createVersion(Plan $plan, int $number, string $status, array $definition, $features, string $notes): PlanVersion
    {
        $version = PlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version_number' => $number,
            'status' => PlanVersion::STATUS_DRAFT,
            'currency_code' => 'INR',
            'monthly_price' => $definition['version']['monthly_price'],
            'annual_price' => $definition['version']['annual_price'],
            'trial_days' => $definition['version']['trial_days'],
            'billing_intervals' => $definition['version']['billing_intervals'],
            'change_notes' => $notes,
        ]);

        $enabled = array_flip($definition['features']);
        foreach ($features as $code => $feature) {
            if ($feature->is_core) {
                $isEnabled = true;
                $value = null;
            } elseif ($feature->isNumeric()) {
                if (! array_key_exists($code, $definition['limits'])) {
                    continue;
                }
                $isEnabled = true;
                $value = $definition['limits'][$code];
            } else {
                $isEnabled = isset($enabled[$code]);
                $value = null;
            }

            $version->entitlements()->create([
                'feature_id' => $feature->id,
                'is_enabled' => $isEnabled,
                'numeric_value' => $value,
            ]);
        }

        $version->forceFill([
            'content_hash' => $this->hasher->hash($version->fresh('entitlements')),
            'status' => $status,
            'effective_from' => now(),
            'published_at' => $status === PlanVersion::STATUS_ACTIVE ? now() : null,
        ])->save();

        return $version;
    }

    /**
     * Keep legacy columns meaningful for rollback to the legacy engine.
     *
     * @param  array<string, mixed>  $definition
     */
    private function mirrorLegacyColumns(Plan $plan, array $definition): void
    {
        $legacyKeys = Feature::query()
            ->whereIn('code', $definition['features'])
            ->whereNotNull('legacy_key')
            ->pluck('legacy_key')
            ->all();

        $plan->forceFill([
            'features' => array_values($legacyKeys),
            'max_storage_mb' => $definition['limits']['STORAGE_MB'] ?? LegacyColumns::UNLIMITED,
            'max_users' => $definition['limits']['STAFF_USER_LIMIT'] ?? LegacyColumns::UNLIMITED,
        ])->save();
    }
}
