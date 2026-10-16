<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 originally required church_leadership_id for source=internal_leadership.
 * Governance ministers now persist via leadership_assignment_id (nullable legacy FK).
 * Align the CHECK with SacramentParticipantValidator and ParticipantSnapshotBuilder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        if (! Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
            return;
        }

        DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_leadership_source_check');
        DB::statement("
            ALTER TABLE sacrament_participants
            ADD CONSTRAINT sacrament_participants_leadership_source_check
            CHECK (
                source <> 'internal_leadership'
                OR church_leadership_id IS NOT NULL
                OR leadership_assignment_id IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_leadership_source_check');

        // Restore the original legacy-only rule only when no assignment-only rows exist.
        $assignmentOnly = 0;
        if (Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
            $assignmentOnly = (int) DB::table('sacrament_participants')
                ->where('source', 'internal_leadership')
                ->whereNull('church_leadership_id')
                ->whereNotNull('leadership_assignment_id')
                ->count();
        }

        if ($assignmentOnly > 0) {
            // Keep the widened rule so rollback cannot leave orphan rows invalid.
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_leadership_source_check
                CHECK (
                    source <> 'internal_leadership'
                    OR church_leadership_id IS NOT NULL
                    OR leadership_assignment_id IS NOT NULL
                )
            ");

            return;
        }

        DB::statement("
            ALTER TABLE sacrament_participants
            ADD CONSTRAINT sacrament_participants_leadership_source_check
            CHECK (source <> 'internal_leadership' OR church_leadership_id IS NOT NULL)
        ");
    }
};
