<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        Schema::table('sacrament_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
                $table->uuid('leadership_assignment_id')->nullable()->after('church_leadership_id');
                $table->foreign('leadership_assignment_id')
                    ->references('id')
                    ->on('leadership_assignments')
                    ->nullOnDelete();
                $table->index('leadership_assignment_id');
            }
        });

        $this->widenLeadershipSourceCheck();
    }

    public function down(): void
    {
        if (! Schema::hasTable('sacrament_participants')) {
            return;
        }

        $this->restoreLegacyLeadershipSourceCheck();

        Schema::table('sacrament_participants', function (Blueprint $table) {
            if (Schema::hasColumn('sacrament_participants', 'leadership_assignment_id')) {
                $table->dropForeign(['leadership_assignment_id']);
                $table->dropColumn('leadership_assignment_id');
            }
        });
    }

    /**
     * Governance ministers use leadership_assignment_id; legacy rows keep church_leadership_id.
     */
    private function widenLeadershipSourceCheck(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
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

    private function restoreLegacyLeadershipSourceCheck(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_leadership_source_check');
        DB::statement("
            ALTER TABLE sacrament_participants
            ADD CONSTRAINT sacrament_participants_leadership_source_check
            CHECK (source <> 'internal_leadership' OR church_leadership_id IS NOT NULL)
        ");
    }
};
