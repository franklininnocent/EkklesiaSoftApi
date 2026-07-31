<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop foreign key and column from bccs.parish_zone_id if present
        if (Schema::hasColumn('bccs', 'parish_zone_id')) {
            Schema::table('bccs', function (Blueprint $table) {
                // Drop FK if it exists (standard constraint name)
                try {
                    $table->dropForeign(['parish_zone_id']);
                } catch (\Throwable $e) {
                    // ignore if FK does not exist
                }
                // Drop index if exists then drop column
                try {
                    $table->dropIndex(['parish_zone_id']);
                } catch (\Throwable $e) {
                    // ignore if index does not exist
                }
                $table->dropColumn('parish_zone_id');
            });
        }

        // Drop column from families.parish_zone_id if present
        if (Schema::hasColumn('families', 'parish_zone_id')) {
            Schema::table('families', function (Blueprint $table) {
                // No FK expected in earlier migration, but attempt safe drop
                try {
                    $table->dropForeign(['parish_zone_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                try {
                    $table->dropIndex(['parish_zone_id']);
                } catch (\Throwable $e) {
                    // ignore
                }
                $table->dropColumn('parish_zone_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add columns without FKs (minimal down implementation)
        if (!Schema::hasColumn('families', 'parish_zone_id')) {
            Schema::table('families', function (Blueprint $table) {
                $table->uuid('parish_zone_id')->nullable()->after('bcc_id')->comment('Parish zone this family belongs to');
                $table->index('parish_zone_id');
            });
        }

        if (!Schema::hasColumn('bccs', 'parish_zone_id')) {
            Schema::table('bccs', function (Blueprint $table) {
                $table->uuid('parish_zone_id')->nullable()->after('description')->comment('Parish zone this BCC belongs to');
                $table->index('parish_zone_id');
            });
        }
    }
};


