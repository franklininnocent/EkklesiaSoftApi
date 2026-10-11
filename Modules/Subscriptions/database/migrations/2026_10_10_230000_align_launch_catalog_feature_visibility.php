<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\LaunchPlanIntentMatrix;
use Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog;

return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('features')) {
            return;
        }

        $internal = LaunchPlanIntentMatrix::internalCatalogFeatureCodes();
        if ($internal !== []) {
            DB::table('features')->whereIn('code', $internal)->update(['is_public' => false]);
        }

        $descriptions = SubscriptionFeatureCapabilityCatalog::allFeatureDescriptions();
        foreach ($descriptions as $code => $description) {
            DB::table('features')->where('code', $code)->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // Visibility rollback is intentionally omitted — Super Admin may have adjusted flags after alignment.
    }
};
