<?php

namespace Modules\Subscriptions\Services\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;

/**
 * Grandfathers pre-catalog subscription_plans rows.
 *
 * free (and any other custom key) become hidden, non-assignable LEGACY_* plans whose
 * v1 reproduces today's entitlements exactly. The "enterprise" row becomes the ENTERPRISE
 * catalog plan: v1 keeps the legacy terms (RETIRED, still pinned by existing tenants) and the
 * bootstrapper adds v2 with the new custom-priced terms.
 */
class LegacyPlanConverter
{
    public const LEGACY_VERSION_NOTE = 'Grandfathered legacy terms';

    public function __construct(private readonly PlanVersionContentHasher $hasher) {}

    /**
     * @return list<string> converted plan codes
     */
    public function convert(): array
    {
        $this->ensureLegacyRows();

        $converted = [];
        $features = Feature::query()->get();

        Plan::withTrashed()->whereNull('code')->orderBy('id')->get()->each(function (Plan $plan) use ($features, &$converted): void {
            $isEnterprise = $plan->key === 'enterprise';
            $code = $isEnterprise ? 'ENTERPRISE' : $this->legacyCode((string) $plan->key);

            $plan->forceFill([
                'code' => $code,
                'slug' => $this->uniqueSlug($isEnterprise ? 'enterprise' : 'legacy-'.$plan->key),
                'short_description' => $plan->short_description ?: $plan->description,
                'pricing_type' => $isEnterprise ? Plan::PRICING_CUSTOM : ((float) $plan->price > 0 ? Plan::PRICING_FIXED : Plan::PRICING_FREE),
                'status' => $plan->trashed() ? Plan::STATUS_ARCHIVED : Plan::STATUS_ACTIVE,
                'is_legacy' => ! $isEnterprise,
                'is_public' => $isEnterprise,
                'is_assignable' => $isEnterprise,
                'active' => $isEnterprise && ! $plan->trashed(),
            ])->save();

            $version = $this->createLegacyVersion($plan, $features, $isEnterprise);

            $metadata = is_array($plan->metadata) ? $plan->metadata : [];
            $metadata['legacy_version_id'] = $version->id;
            $metadata['legacy_key'] = $plan->key;
            $plan->forceFill(['metadata' => $metadata])->save();

            $converted[] = $code;
        });

        return $converted;
    }

    /**
     * Tenants may reference free even where the legacy plan row was never
     * seeded; recreate them from config so every tenant can be grandfathered.
     */
    private function ensureLegacyRows(): void
    {
        foreach (array_keys(SubscriptionCatalogDefinition::legacyPlanCodes()) as $key) {
            $config = config("tenants.plans.{$key}");
            if (! is_array($config) || Plan::withTrashed()->where('key', $key)->exists()) {
                continue;
            }

            Plan::query()->create([
                'key' => $key,
                'name' => (string) ($config['name'] ?? ucfirst($key)),
                'description' => null,
                'price' => $config['price'] ?? 0,
                'max_users' => (int) ($config['max_users'] ?? 10),
                'max_storage_mb' => (int) ($config['max_storage_mb'] ?? 100),
                'features' => array_values((array) ($config['features'] ?? [])),
                'display_order' => 900,
                'active' => false,
                'is_default' => false,
            ]);
        }
    }

    /**
     * @param  Collection<int, Feature>  $features
     */
    private function createLegacyVersion(Plan $plan, $features, bool $retired): PlanVersion
    {
        $legacyKeys = $this->decodeFeatures($plan->getRawOriginal('features'));
        if ($legacyKeys === []) {
            $legacyKeys = array_values((array) config("tenants.plans.{$plan->key}.features", []));
        }
        $isFree = (float) $plan->price <= 0;

        $version = PlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version_number' => 1,
            'status' => PlanVersion::STATUS_DRAFT,
            'currency_code' => 'INR',
            'monthly_price' => $isFree ? '0.00' : (string) $plan->price,
            'annual_price' => null,
            'trial_days' => $plan->key === 'free' ? (int) config('tenants.trial_days', 30) : null,
            'billing_intervals' => [$isFree ? PlanVersion::INTERVAL_NONE : PlanVersion::INTERVAL_MONTHLY],
            'change_notes' => self::LEGACY_VERSION_NOTE,
        ]);

        foreach ($features as $feature) {
            if ($feature->is_core) {
                $enabled = true;
            } elseif ($feature->legacy_key) {
                $enabled = in_array($feature->legacy_key, $legacyKeys, true);
            } elseif ($feature->isNumeric()) {
                $enabled = true;
            } else {
                $enabled = (bool) $feature->legacy_default;
            }

            $version->entitlements()->create([
                'feature_id' => $feature->id,
                'is_enabled' => $enabled,
                'numeric_value' => null,
            ]);
        }

        $version->forceFill([
            'content_hash' => $this->hasher->hash($version->fresh('entitlements')),
            'status' => $retired ? PlanVersion::STATUS_RETIRED : PlanVersion::STATUS_ACTIVE,
            'effective_from' => $plan->created_at ?? now(),
            'published_at' => $plan->created_at ?? now(),
            'retired_at' => $retired ? now() : null,
        ])->save();

        return $version;
    }

    /**
     * @return list<string>
     */
    public function decodeFeatures(mixed $raw): array
    {
        $value = $raw;
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                break;
            }
            $value = $decoded;
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function legacyCode(string $key): string
    {
        $known = SubscriptionCatalogDefinition::legacyPlanCodes();
        $base = $known[$key] ?? 'LEGACY_'.strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $key), '_'));
        $base = substr($base === 'LEGACY_' ? 'LEGACY_PLAN' : $base, 0, 60);

        $code = $base;
        $suffix = 2;
        while (Plan::withTrashed()->where('code', $code)->exists()) {
            $code = substr($base, 0, 56).'_'.$suffix++;
        }

        return $code;
    }

    private function uniqueSlug(string $base): string
    {
        $base = Str::slug($base) ?: 'plan';
        $slug = $base;
        $suffix = 2;
        while (Plan::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
