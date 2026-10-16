<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = SubscriptionFeatureCapabilityCatalog::allFeatureDescriptions();
        foreach ($descriptions as $code => $description) {
            DB::table('features')->where('code', $code)->update([
                'description' => $description,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Descriptions are product copy; leave unchanged on rollback.
    }
};
