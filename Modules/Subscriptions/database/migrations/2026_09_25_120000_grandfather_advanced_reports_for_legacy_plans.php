<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\EntitlementCacheVersion;

/**
 * The legacy `advanced_reporting` plan label was never enforced, so every church had advanced
 * reports before plan entitlements. Detach the label and enable the feature on grandfathered
 * LEGACY_* versions so enforce mode keeps what those churches already had. Only enables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $featureId = DB::table('features')->where('code', 'ADVANCED_REPORTS')->value('id');
        if (! $featureId) {
            return;
        }

        DB::table('features')->where('id', $featureId)->where('legacy_key', 'advanced_reporting')->update([
            'legacy_key' => null,
            'updated_at' => now(),
        ]);

        $legacyVersionIds = DB::table('plan_versions')
            ->join('subscription_plans', 'subscription_plans.id', '=', 'plan_versions.plan_id')
            ->where('subscription_plans.is_legacy', true)
            ->pluck('plan_versions.id');

        if ($legacyVersionIds->isNotEmpty()) {
            DB::table('plan_entitlements')
                ->where('feature_id', $featureId)
                ->whereIn('plan_version_id', $legacyVersionIds)
                ->where('is_enabled', false)
                ->update(['is_enabled' => true, 'updated_at' => now()]);
        }

        EntitlementCacheVersion::bumpCatalog();
    }

    public function down(): void
    {
        // Corrective grant; reverting would remove access churches already had.
    }
};
