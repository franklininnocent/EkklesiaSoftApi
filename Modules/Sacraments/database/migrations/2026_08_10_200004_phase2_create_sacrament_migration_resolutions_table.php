<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — sacrament_migration_resolutions (§5.5). Used by Phase 9 migration job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacrament_migration_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('legacy_sacrament_id')->constrained('sacraments')->cascadeOnDelete();

            $table->string('participant_role', 40);
            $table->string('legacy_name')->nullable();
            $table->date('legacy_dob')->nullable();

            $table->uuid('candidate_member_id')->nullable();
            $table->string('confidence', 20)->default('none');
            $table->string('resolution', 20)->default('unresolved');

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('migration_key')->unique();

            $table->timestamps();

            $table->foreign('candidate_member_id')
                ->references('id')->on('family_members')
                ->nullOnDelete();

            $table->index(['tenant_id', 'legacy_sacrament_id']);
            $table->index(['tenant_id', 'resolution']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE sacrament_migration_resolutions
                ADD CONSTRAINT sacrament_migration_resolutions_confidence_check
                CHECK (confidence IN ('exact','ambiguous','none'))
            ");
            DB::statement("
                ALTER TABLE sacrament_migration_resolutions
                ADD CONSTRAINT sacrament_migration_resolutions_resolution_check
                CHECK (resolution IN ('member','external','unresolved'))
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_migration_resolutions DROP CONSTRAINT IF EXISTS sacrament_migration_resolutions_resolution_check');
            DB::statement('ALTER TABLE sacrament_migration_resolutions DROP CONSTRAINT IF EXISTS sacrament_migration_resolutions_confidence_check');
        }

        Schema::dropIfExists('sacrament_migration_resolutions');
    }
};
