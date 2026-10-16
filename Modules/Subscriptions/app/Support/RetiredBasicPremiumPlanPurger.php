<?php

namespace Modules\Subscriptions\Support;

use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanVersion;

/**
 * Moves any church still on the retired basic/premium keys onto Starter/Standard, then
 * permanently deletes every catalog row (including soft-deleted/archived) for those plans.
 */
final class RetiredBasicPremiumPlanPurger
{
    private const REMOVED_KEYS = ['basic', 'premium'];

    private const REMOVED_CODES = ['LEGACY_BASIC', 'LEGACY_PREMIUM'];

    /** @var array<string, string> */
    private const TENANT_KEY_MAP = [
        'basic' => 'starter',
        'premium' => 'standard',
    ];

    /** @var array<string, string> */
    private const PLAN_CODE_MAP = [
        'LEGACY_BASIC' => 'STARTER',
        'LEGACY_PREMIUM' => 'STANDARD',
    ];

    public function purge(): void
    {
        $removedPlanIds = Plan::withTrashed()
            ->where(function ($q): void {
                $q->whereIn('key', self::REMOVED_KEYS)->orWhereIn('code', self::REMOVED_CODES);
            })
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($removedPlanIds === []) {
            return;
        }

        $targetVersions = $this->activeVersionIds();
        $targetPlanIds = $this->replacementTargets($removedPlanIds, $targetVersions);

        foreach (self::TENANT_KEY_MAP as $from => $to) {
            DB::table('tenants')->where('plan', $from)->update(['plan' => $to, 'updated_at' => now()]);
        }

        foreach ($targetPlanIds as $removedId => $target) {
            DB::table('tenant_subscriptions')
                ->where('plan_id', $removedId)
                ->update([
                    'plan_id' => $target['plan_id'],
                    'plan_version_id' => $target['plan_version_id'],
                    'updated_at' => now(),
                ]);

            DB::table('subscription_upgrade_requests')
                ->where('current_plan_id', $removedId)
                ->update(['current_plan_id' => $target['plan_id'], 'updated_at' => now()]);

            DB::table('subscription_upgrade_requests')
                ->where('requested_plan_id', $removedId)
                ->whereIn('status', ['PENDING', 'INFO_REQUESTED'])
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);

            DB::table('subscription_upgrade_requests')
                ->where('requested_plan_id', $removedId)
                ->whereNotIn('status', ['PENDING', 'INFO_REQUESTED'])
                ->update(['requested_plan_id' => $target['plan_id'], 'updated_at' => now()]);
        }

        $versionIds = DB::table('plan_versions')->whereIn('plan_id', $removedPlanIds)->pluck('id');

        if ($versionIds->isNotEmpty()) {
            DB::table('subscription_catalog_audits')
                ->where('entity_type', 'plan_version')
                ->whereIn('entity_id', $versionIds)
                ->delete();

            DB::table('plan_entitlements')->whereIn('plan_version_id', $versionIds)->delete();
            DB::table('plan_versions')->whereIn('id', $versionIds)->delete();
        }

        DB::table('subscription_catalog_audits')
            ->where('entity_type', 'plan')
            ->whereIn('entity_id', $removedPlanIds)
            ->delete();

        Plan::withTrashed()->whereIn('id', $removedPlanIds)->each(static fn (Plan $plan) => $plan->forceDelete());

        EntitlementCacheVersion::bumpCatalog();
    }

    /**
     * @return array<string, int>
     */
    private function activeVersionIds(): array
    {
        $targetVersions = [];
        foreach (self::PLAN_CODE_MAP as $targetCode) {
            $versionId = DB::table('plan_versions')
                ->join('subscription_plans', 'subscription_plans.id', '=', 'plan_versions.plan_id')
                ->where('subscription_plans.code', $targetCode)
                ->where('plan_versions.status', PlanVersion::STATUS_ACTIVE)
                ->value('plan_versions.id');
            if ($versionId) {
                $targetVersions[$targetCode] = (int) $versionId;
            }
        }

        return $targetVersions;
    }

    /**
     * @param  list<int>  $removedPlanIds
     * @param  array<string, int>  $targetVersions
     * @return array<int, array{plan_id: int, plan_version_id: int, tenant_key: string}>
     */
    private function replacementTargets(array $removedPlanIds, array $targetVersions): array
    {
        $targetPlanIds = [];
        foreach (self::PLAN_CODE_MAP as $removedCode => $targetCode) {
            $removedId = Plan::withTrashed()->where('code', $removedCode)->value('id')
                ?? Plan::withTrashed()->where('key', self::tenantKeyForRemovedCode($removedCode))->value('id');
            $targetPlanId = DB::table('subscription_plans')->where('code', $targetCode)->value('id');
            if ($removedId && $targetPlanId && isset($targetVersions[$targetCode])) {
                $targetPlanIds[(int) $removedId] = [
                    'plan_id' => (int) $targetPlanId,
                    'plan_version_id' => $targetVersions[$targetCode],
                    'tenant_key' => self::TENANT_KEY_MAP[self::tenantKeyForRemovedCode($removedCode)],
                ];
            }
        }

        return $targetPlanIds;
    }

    private static function tenantKeyForRemovedCode(string $code): string
    {
        return match ($code) {
            'LEGACY_BASIC' => 'basic',
            'LEGACY_PREMIUM' => 'premium',
            default => strtolower(str_replace('LEGACY_', '', $code)),
        };
    }
}
