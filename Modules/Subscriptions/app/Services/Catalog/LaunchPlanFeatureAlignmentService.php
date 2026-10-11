<?php

namespace Modules\Subscriptions\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\PlanVersionService;
use Modules\Subscriptions\Support\LaunchPlanIntentMatrix;
use Modules\Subscriptions\Support\SubscriptionCatalogDefinition;

final class LaunchPlanFeatureAlignmentService
{
    public function __construct(
        private readonly PlanVersionService $versions,
    ) {}

    /**
     * @return array{plans: list<array<string, mixed>>, applied: list<string>, skipped: list<string>}
     */
    public function run(bool $apply, ?string $forceCode = null, ?User $actor = null): array
    {
        $forceCode = $forceCode !== null ? strtoupper($forceCode) : null;
        $plans = [];
        $applied = [];
        $skipped = [];

        foreach (LaunchPlanIntentMatrix::LAUNCH_PLAN_CODES as $code) {
            $report = $this->analyzePlan($code, $forceCode);
            $plans[] = $report;

            if ($report['classification'] === 'skip') {
                $skipped[] = $code;

                continue;
            }

            if ($report['classification'] === 'aligned') {
                $skipped[] = $code;

                continue;
            }

            if (! $apply) {
                continue;
            }

            if ($report['classification'] !== 'eligible' && ! ($forceCode === $code && $report['classification'] === 'conflict')) {
                $skipped[] = $code;

                continue;
            }

            $this->publishAlignedVersion($report, $actor, $forceCode === $code);
            $applied[] = $code;
        }

        return ['plans' => $plans, 'applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzePlan(string $code, ?string $forceCode): array
    {
        $plan = Plan::query()->where('code', $code)->first();
        if (! $plan || $plan->is_legacy || $plan->isArchived() || $plan->trashed()) {
            return [
                'code' => $code,
                'classification' => 'skip',
                'reason' => 'Not an eligible launch plan (missing, legacy, or archived).',
            ];
        }

        $active = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->where('status', PlanVersion::STATUS_ACTIVE)
            ->with('entitlements.feature')
            ->first();

        if (! $active) {
            return [
                'code' => $code,
                'classification' => 'skip',
                'reason' => 'No ACTIVE catalog version.',
            ];
        }

        $draftExists = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->where('status', PlanVersion::STATUS_DRAFT)
            ->exists();

        $runtime = $this->runtimeSnapshot($active);
        $intended = LaunchPlanIntentMatrix::intendedFor($code);
        $previous = LaunchPlanIntentMatrix::previousSeedFor($code);

        $classification = 'conflict';
        $reason = 'ACTIVE entitlements differ from both the previous seed and the intended launch matrix.';

        if (LaunchPlanIntentMatrix::snapshotsEqual($runtime, $intended)) {
            $classification = 'aligned';
            $reason = 'Already matches intended launch matrix.';
        } elseif (LaunchPlanIntentMatrix::snapshotsEqual($runtime, $previous)) {
            $classification = 'eligible';
            $reason = 'Matches previous seed; safe to publish intended matrix.';
        } elseif ($forceCode === $code) {
            $classification = 'eligible';
            $reason = 'Forced alignment despite custom drift.';
        }

        if ($draftExists && $classification === 'eligible') {
            $classification = 'skip';
            $reason = 'An open draft version exists; publish or delete it first.';
        }

        $planVersionIds = PlanVersion::query()->where('plan_id', $plan->id)->pluck('id');
        $pinnedCurrent = TenantSubscription::query()->where('plan_version_id', $active->id)->count();
        $pinnedAny = TenantSubscription::query()->whereIn('plan_version_id', $planVersionIds)->count();
        $tenantIds = TenantSubscription::query()->whereIn('plan_version_id', $planVersionIds)->pluck('tenant_id');
        $activeOverrides = $tenantIds->isEmpty()
            ? 0
            : TenantEntitlementOverride::query()->open()->whereIn('tenant_id', $tenantIds)->count();

        $seedPricing = LaunchPlanIntentMatrix::seedPricingFor($code);
        $pricingDrift = $this->pricingDiffersFromSeed($active, $seedPricing, $plan);

        return [
            'code' => $code,
            'plan_id' => $plan->id,
            'active_version_id' => $active->id,
            'active_version_number' => $active->version_number,
            'classification' => $classification,
            'reason' => $reason,
            'current' => $runtime,
            'intended' => $intended,
            'previous_seed' => $previous,
            'pricing' => [
                'monthly_price' => $active->monthly_price,
                'annual_price' => $active->annual_price,
                'trial_days' => $active->trial_days,
                'currency_code' => $active->currency_code,
            ],
            'seed_pricing' => $seedPricing,
            'pricing_differs_from_install_seed' => $pricingDrift,
            'pinned_current_subscriptions' => $pinnedCurrent,
            'pinned_any_version_subscriptions' => $pinnedAny,
            'active_entitlement_overrides' => $activeOverrides,
            'has_open_draft' => $draftExists,
        ];
    }

    /**
     * @param  array{monthly_price: string|null, annual_price: string|null, trial_days: int|null}  $seed
     */
    private function pricingDiffersFromSeed(PlanVersion $active, array $seed, Plan $plan): bool
    {
        if ($plan->pricing_type === Plan::PRICING_CUSTOM) {
            return false;
        }

        $norm = static fn ($v) => $v === null || $v === '' ? null : (string) $v;

        return $norm($active->monthly_price) !== $norm($seed['monthly_price'])
            || $norm($active->annual_price) !== $norm($seed['annual_price'])
            || (int) ($active->trial_days ?? 0) !== (int) ($seed['trial_days'] ?? 0);
    }

    /**
     * Publish a new ACTIVE version for a launch plan from the approved catalog matrix,
     * copying commercial fields from the current ACTIVE version.
     */
    public function publishIntendedVersionForLaunchPlan(string $planCode, User $actor, string $changeNote): PlanVersion
    {
        $code = strtoupper($planCode);
        $plan = Plan::query()->where('code', $code)->firstOrFail();
        $active = PlanVersion::query()
            ->where('plan_id', $plan->id)
            ->where('status', PlanVersion::STATUS_ACTIVE)
            ->firstOrFail();

        return $this->publishIntendedFromActive($plan, $active, $code, $actor, $changeNote);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function publishAlignedVersion(array $report, ?User $actor, bool $forced): void
    {
        $plan = Plan::query()->findOrFail($report['plan_id']);
        $active = PlanVersion::query()->findOrFail($report['active_version_id']);
        $note = $forced
            ? 'Launch plan feature alignment (forced)'
            : 'Launch plan feature alignment';

        $actor ??= \Modules\Subscriptions\Support\SubscriptionConsoleActor::resolve();
        $this->publishIntendedFromActive($plan, $active, (string) $report['code'], $actor, $note);
    }

    private function publishIntendedFromActive(Plan $plan, PlanVersion $active, string $code, User $actor, string $changeNote): PlanVersion
    {
        $definition = LaunchPlanIntentMatrix::intendedFor($code);
        $planDefinition = null;
        foreach (SubscriptionCatalogDefinition::plans() as $row) {
            if ($row['code'] === $code) {
                $planDefinition = $row;
                break;
            }
        }

        return DB::transaction(function () use ($plan, $active, $definition, $planDefinition, $actor, $changeNote): PlanVersion {
            $draft = $this->versions->createDraft($plan, $actor, $active);
            $rows = $this->buildEntitlementPayload($planDefinition ?? ['features' => $definition['features'], 'limits' => $definition['limits']]);
            $draft = $this->versions->setEntitlements($draft, $rows, $actor);

            return $this->versions->publish($draft, $actor, null, $changeNote);
        });
    }

    /**
     * @return array{features: list<string>, limits: array<string, int|null>}
     */
    private function runtimeSnapshot(PlanVersion $version): array
    {
        $enabled = [];
        $limits = [];

        foreach ($version->entitlements as $entitlement) {
            $feature = $entitlement->feature;
            if (! $feature) {
                continue;
            }
            $code = (string) $feature->code;
            if ($feature->isNumeric()) {
                if ($entitlement->is_enabled) {
                    $limits[$code] = $entitlement->numeric_value === null ? null : (int) $entitlement->numeric_value;
                }

                continue;
            }
            if ($feature->is_core) {
                continue;
            }
            if ($entitlement->is_enabled) {
                $enabled[] = $code;
            }
        }

        return LaunchPlanIntentMatrix::snapshotFromRuntime($enabled, $limits);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array{feature_code: string, is_enabled: bool, numeric_value: int|null, tier_value: null}>
     */
    private function buildEntitlementPayload(array $definition): array
    {
        $features = Feature::query()->get()->keyBy('code');
        $enabled = array_flip($definition['features'] ?? []);
        $limits = $definition['limits'] ?? [];
        $rows = [];

        foreach ($features as $code => $feature) {
            if ($feature->is_core) {
                $rows[] = [
                    'feature_code' => $code,
                    'is_enabled' => true,
                    'numeric_value' => null,
                    'tier_value' => null,
                ];

                continue;
            }

            if ($feature->isNumeric()) {
                if (! array_key_exists($code, $limits)) {
                    continue;
                }
                $rows[] = [
                    'feature_code' => $code,
                    'is_enabled' => true,
                    'numeric_value' => $limits[$code],
                    'tier_value' => null,
                ];

                continue;
            }

            $rows[] = [
                'feature_code' => $code,
                'is_enabled' => isset($enabled[$code]),
                'numeric_value' => null,
                'tier_value' => null,
            ];
        }

        return $rows;
    }
}
