<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_plan_catalog_revisions', function (Blueprint $table) {
            $table->json('eligible_payload')->nullable()->after('payload');
            $table->json('feature_visibility')->nullable()->after('eligible_payload');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('UPDATE marketing_plan_catalog_revisions SET eligible_payload = payload WHERE eligible_payload IS NULL');
            DB::statement("UPDATE marketing_plan_catalog_revisions SET feature_visibility = '{}'::json WHERE feature_visibility IS NULL");
        } else {
            DB::table('marketing_plan_catalog_revisions')->whereNull('eligible_payload')->update([
                'eligible_payload' => DB::raw('payload'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('marketing_plan_catalog_revisions', function (Blueprint $table) {
            $table->dropColumn(['eligible_payload', 'feature_visibility']);
        });
    }
};
