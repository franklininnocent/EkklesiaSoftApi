<?php

namespace Modules\Subscriptions\Services\Catalog;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\SubscriptionAuditService;
use Modules\Tenants\Models\Tenant;

/**
 * Pins every tenant without a CURRENT subscription to the grandfathered version of its
 * plan and records per-tenant overrides wherever the tenant's own feature list differs
 * from that version, so no tenant gains or loses access (zero-loss rule).
 */
class TenantSubscriptionBackfiller
{
    public const OVERRIDE_REASON = 'Preserved pre-catalog entitlement (backfill)';

    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly SubscriptionAuditService $audit,
    ) {}

    /**
     * @return array{tenants_backfilled: int, overrides_created: int, fallback_plan_tenants: list<int>, skipped: int}
     */
    public function run(bool $dryRun = false): array
    {
        $stats = ['tenants_backfilled' => 0, 'overrides_created' => 0, 'fallback_plan_tenants' => [], 'skipped' => 0];
        $legacyFeatures = Feature::query()->whereNotNull('legacy_key')->get();
        $fallback = Plan::withTrashed()->where('code', 'LEGACY_FREE')->first();

        Tenant::withTrashed()->orderBy('id')->chunkById(200, function ($tenants) use (&$stats, $legacyFeatures, $fallback, $dryRun): void {
            foreach ($tenants as $tenant) {
                /** @var Tenant $tenant */
                if (TenantSubscription::query()->forTenant((int) $tenant->id)->current()->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                $plan = Plan::withTrashed()->where('key', $tenant->plan)->whereNotNull('code')->first();
                if (! $plan) {
                    $plan = $fallback;
                    $stats['fallback_plan_tenants'][] = (int) $tenant->id;
                }
                $version = $plan ? $this->grandfatheredVersion($plan) : null;
                if (! $plan || ! $version) {
                    $stats['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['tenants_backfilled']++;
                    $stats['overrides_created'] += count($this->diffOverrides($tenant, $version, $legacyFeatures));

                    continue;
                }

                DB::transaction(function () use ($tenant, $plan, $version, $legacyFeatures, &$stats): void {
                    $subscription = TenantSubscription::query()->create([
                        'tenant_id' => $tenant->id,
                        'plan_id' => $plan->id,
                        'plan_version_id' => $version->id,
                        'record_status' => TenantSubscription::RECORD_CURRENT,
                        'billing_interval' => PlanVersion::INTERVAL_NONE,
                        'currency_code' => $tenant->currency_code ?: 'INR',
                        'contracted_price' => null,
                        'starts_at' => $tenant->created_at ?? now(),
                        'source' => TenantSubscription::SOURCE_BACKFILL,
                        'reason' => 'Grandfathered from legacy plan "'.$tenant->plan.'"',
                    ]);

                    $overrides = $this->diffOverrides($tenant, $version, $legacyFeatures);
                    foreach ($overrides as $override) {
                        TenantEntitlementOverride::query()->create($override + [
                            'tenant_id' => $tenant->id,
                            'reason' => self::OVERRIDE_REASON,
                        ]);
                    }

                    $this->audit->tenant((int) $tenant->id, 'subscription_backfilled', ['plan' => $tenant->plan], [
                        'plan_code' => $plan->code,
                        'plan_version_id' => $version->id,
                        'subscription_id' => $subscription->id,
                        'overrides' => array_map(static fn (array $o) => ['feature_id' => $o['feature_id'], 'mode' => $o['mode']], $overrides),
                    ], 'backfill', null, null, 'system');

                    $stats['tenants_backfilled']++;
                    $stats['overrides_created'] += count($overrides);
                });

                $this->resolver->forget((int) $tenant->id);
            }
        });

        return $stats;
    }

    public function grandfatheredVersion(Plan $plan): ?PlanVersion
    {
        $legacyVersionId = is_array($plan->metadata) ? ($plan->metadata['legacy_version_id'] ?? null) : null;
        if ($legacyVersionId) {
            $version = PlanVersion::query()->where('plan_id', $plan->id)->find($legacyVersionId);
            if ($version) {
                return $version;
            }
        }

        return PlanVersion::query()->where('plan_id', $plan->id)->where('status', PlanVersion::STATUS_ACTIVE)->first();
    }

    /**
     * @param  Collection<int, Feature>  $legacyFeatures
     * @return list<array{feature_id: int, mode: string}>
     */
    private function diffOverrides(Tenant $tenant, PlanVersion $version, $legacyFeatures): array
    {
        $entitlements = $version->entitlements()->get()->keyBy('feature_id');
        $overrides = [];

        foreach ($legacyFeatures as $feature) {
            $legacyDecision = $tenant->legacyFeatureDecision((string) $feature->legacy_key);
            $versionDecision = (bool) ($entitlements[$feature->id]->is_enabled ?? false);
            if ($legacyDecision === $versionDecision) {
                continue;
            }
            $overrides[] = [
                'feature_id' => (int) $feature->id,
                'mode' => $legacyDecision ? TenantEntitlementOverride::MODE_ENABLE : TenantEntitlementOverride::MODE_DISABLE,
            ];
        }

        return $overrides;
    }
}
