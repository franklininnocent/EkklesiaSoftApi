<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — sacrament_participants (§5.3). Empty until Phase 3 dual-write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacrament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('sacrament_id')->constrained('sacraments')->cascadeOnDelete();

            $table->string('role', 40);
            $table->string('source', 40);

            $table->uuid('family_member_id')->nullable();
            $table->unsignedBigInteger('church_leadership_id')->nullable();
            $table->integer('sort_order')->default(0);

            // Affiliation (independent of source) — ADR-12
            $table->string('affiliation_type', 30)->nullable();
            $table->string('affiliation_parish_name')->nullable();
            $table->text('affiliation_parish_address')->nullable();
            $table->string('affiliation_diocese_name')->nullable();
            $table->string('affiliation_diocese_region')->nullable();
            $table->string('affiliation_diocese_country')->nullable();

            // External identity
            $table->string('external_full_name')->nullable();
            $table->date('external_date_of_birth')->nullable();
            $table->string('external_gender', 20)->nullable();
            $table->text('external_address')->nullable();
            $table->string('external_title', 50)->nullable();
            $table->string('external_minister_role', 80)->nullable();

            // Server-built historical snapshot (ADR-02); default {} for empty Phase 2 rows
            $table->json('snapshot_json');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('family_member_id')
                ->references('id')->on('family_members')
                ->nullOnDelete();
            $table->foreign('church_leadership_id')
                ->references('id')->on('church_leadership')
                ->nullOnDelete();

            $table->index(['tenant_id', 'sacrament_id']);
            $table->index(['tenant_id', 'family_member_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['sacrament_id', 'role', 'sort_order'], 'sacrament_participants_role_sort_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_role_check
                CHECK (role IN (
                    'recipient','bride','groom','father','mother',
                    'godfather','godmother','sponsor','witness','minister',
                    'candidate','co_consecrator'
                ))
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_source_check
                CHECK (source IN ('member','internal_leadership','external','unresolved'))
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_member_source_check
                CHECK (source <> 'member' OR family_member_id IS NOT NULL)
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_leadership_source_check
                CHECK (source <> 'internal_leadership' OR church_leadership_id IS NOT NULL)
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_external_source_check
                CHECK (source <> 'external' OR external_full_name IS NOT NULL)
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_affiliation_other_check
                CHECK (
                    affiliation_type IS DISTINCT FROM 'other'
                    OR (affiliation_parish_name IS NOT NULL AND affiliation_diocese_name IS NOT NULL)
                )
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_affiliation_other_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_external_source_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_leadership_source_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_member_source_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_source_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_role_check');
        }

        Schema::dropIfExists('sacrament_participants');
    }
};
