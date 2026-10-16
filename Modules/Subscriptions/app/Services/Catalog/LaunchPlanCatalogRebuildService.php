<?php

namespace Modules\Subscriptions\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Exceptions\SubscriptionException;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Subscriptions\Models\TenantSubscription;
use Modules\Subscriptions\Services\PlanVersionService;
use Modules\Subscriptions\Services\SubscriptionPlanChangeService;
use Modules\Subscriptions\Support\LaunchPlanIntentMatrix;
use Modules\Subscriptions\Support\SubscriptionConsoleActor;

/**
 * Rebuild launch-plan catalog versions from the approved matrix: publish a fresh ACTIVE
 * version per plan, optionally prune unreferenced history, never auto-migrate pinned tenants.
 */
final class LaunchPlanCatalogRebuildService
{
    public function __construct(
        private readonly LaunchPlanFeatureAlignmentService $alignment,
        private readonly PlanVersionService $versions,
        private readonly SubscriptionPlanChangeService $planChanges,
    ) {}

    /**
     * @return array{
     *     plans: list<array<string, mixed>>,
     *     published: list<string>,
     *     pruned_version_ids: array<string, list<int>>,
     *     skipped: list<string>,
     *     migration: array<string, mixed>|null
     * }
     */
    public function run(
        bool $apply,
        bool $pruneUnreferenced,
        bool $migrateTenants,
        ?User $actor = null,
    ): array {
        $plans = [];
        $published = [];
        $prunedVersionIds = [];
        $skipped = [];
        $migration = null;

        foreach (LaunchPlanIntentMatrix::LAUNCH_PLAN_CODES as $code) {
            $row = $this->analyze($code);
            $plans[] = $row;

            if ($row['classification'] === 'skip') {
                $skipped[] = $code;

                continue;
            }

            if (! $apply) {
                continue;
            }

            $actor ??= SubscriptionConsoleActor::resolve();
            $planId = (int) $row['plan_id'];

            $this->removeUnreferencedDrafts($planId, $actor);

            $this->alignment->publishIntendedVersionForLaunchPlan(
                $code,
                $actor,
                'Launch plan catalog rebuild (approved matrix)',
            );
            $published[] = $code;

            $activeId = (int) Plan::query()->findOrFail($planId)->fresh()->activeVersion->id;

            if ($pruneUnreferenced) {
                $prunedVersionIds[$code] = $this->pruneUnreferencedVersions($planId, $activeId, $actor);
            }
        }

        if ($apply && $migrateTenants && $published !== []) {
            $actor ??= SubscriptionConsoleActor::resolve();
            $migration = $this->migrateTenantsToNewActive($published, $actor);
        }

        return [
            'plans' => $plans,
            'published' => $published,
            'pruned_version_ids' => $prunedVersionIds,
            'skipped' => $skipped,
            'migration' => $migration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function analyze(string $code): array
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

        $versionIds = PlanVersion::query()->where('plan_id', $plan->id)->pluck('id');
        $referenced = TenantSubscription::query()
            ->whereIn('plan_version_id', $versionIds)
            ->distinct()
            ->pluck('plan_version_id');
        $pinnedCurrent = TenantSubscription::query()->where('plan_version_id', $active->id)->count();

        $intended = LaunchPlanIntentMatrix::intendedFor($code);
        $runtime = $this->runtimeSnapshot($active);
        $aligned = LaunchPlanIntentMatrix::snapshotsEqual($runtime, $intended);

        $prunable = $versionIds
            ->diff($referenced)
            ->reject(static fn ($id) => (int) $id === (int) $active->id)
            ->values()
            ->all();

        return [
            'code' => $code,
            'plan_id' => $plan->id,
            'classification' => 'rebuild',
            'reason' => $aligned
                ? 'ACTIVE already matches the matrix; rebuild will still publish a fresh version.'
                : 'ACTIVE differs from the approved matrix; rebuild will publish a fresh version.',
            'active_version_id' => $active->id,
            'active_version_number' => $active->version_number,
            'intended' => $intended,
            'current' => $runtime,
            'aligned_with_matrix' => $aligned,
            'pinned_current_subscriptions' => $pinnedCurrent,
            'referenced_version_count' => $referenced->count(),
            'prunable_version_count' => count($prunable),
            'prunable_version_ids' => $prunable,
        ];
    }

    private function removeUnreferencedDrafts(int $planId, User $actor): void
    {
        $drafts = PlanVersion::query()
            ->where('plan_id', $planId)
            ->where('status', PlanVersion::STATUS_DRAFT)
            ->get();

        foreach ($drafts as $draft) {
            if (TenantSubscription::query()->where('plan_version_id', $draft->id)->exists()) {
                throw SubscriptionException::invalidState(
                    "Plan {$planId} has a draft version referenced by tenant subscriptions; resolve manually before rebuild."
                );
            }
            $this->versions->deleteDraft($draft, $actor);
        }
    }

    /**
     * @return list<int>
     */
    private function pruneUnreferencedVersions(int $planId, int $keepActiveId, User $actor): array
    {
        $referenced = TenantSubscription::query()
            ->whereIn('plan_version_id', PlanVersion::query()->where('plan_id', $planId)->select('id'))
            ->distinct()
            ->pluck('plan_version_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $deleted = [];
        $candidates = PlanVersion::query()
            ->where('plan_id', $planId)
            ->where('id', '!=', $keepActiveId)
            ->orderBy('version_number')
            ->get();

        foreach ($candidates as $version) {
            if (in_array((int) $version->id, $referenced, true)) {
                continue;
            }

            DB::transaction(function () use ($version): void {
                DB::table('plan_entitlements')->where('plan_version_id', $version->id)->delete();
                DB::table('plan_versions')->where('id', $version->id)->delete();
            });

            $deleted[] = (int) $version->id;
        }

        return $deleted;
    }

    /**
     * @param  list<string>  $publishedPlanCodes
     * @return array<string, mixed>
     */
    private function migrateTenantsToNewActive(array $publishedPlanCodes, User $actor): array
    {
        $results = [];
        foreach ($publishedPlanCodes as $code) {
            $plan = Plan::query()->where('code', $code)->firstOrFail();
            $active = $plan->fresh()->activeVersion;
            if (! $active) {
                continue;
            }

            $results[$code] = $this->planChanges->migrateVersionTenants($active, [
                'reason' => 'Launch plan catalog rebuild (explicit tenant migration)',
                'confirm_impact' => true,
            ], $actor);
        }

        return $results;
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
}
