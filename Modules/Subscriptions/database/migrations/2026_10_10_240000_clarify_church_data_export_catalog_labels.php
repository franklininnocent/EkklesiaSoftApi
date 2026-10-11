<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Subscriptions\Support\SubscriptionFeatureCapabilityCatalog;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        DB::table('features')
            ->where('code', 'IMPORT_EXPORT')
            ->update(['name' => 'Church Data Export']);

        $descriptions = SubscriptionFeatureCapabilityCatalog::allFeatureDescriptions();
        foreach ($descriptions as $code => $description) {
            DB::table('features')->where('code', $code)->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // Customer-facing copy rollback omitted — Super Admin may have edited descriptions after deploy.
    }
};
