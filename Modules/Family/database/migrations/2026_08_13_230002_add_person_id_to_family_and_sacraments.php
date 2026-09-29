<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-24 — nullable person_id FKs and participant source=person.
 * family_members.person_id is made NOT NULL in the following migration after backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_members', function (Blueprint $table) {
            $table->uuid('person_id')->nullable()->after('family_id');
            $table->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();
            $table->index('person_id');
        });

        Schema::table('sacraments', function (Blueprint $table) {
            $table->uuid('person_id')->nullable()->after('family_id');
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->index(['tenant_id', 'person_id'], 'sacraments_tenant_person_idx');
        });

        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->uuid('person_id')->nullable()->after('family_member_id');
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->index(['tenant_id', 'person_id'], 'sacrament_participants_tenant_person_idx');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_source_check');
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_source_check
                CHECK (source IN ('member','internal_leadership','external','unresolved','person'))
            ");
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_person_source_check
                CHECK (source <> 'person' OR person_id IS NOT NULL)
            ");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_person_source_check');
            DB::statement('ALTER TABLE sacrament_participants DROP CONSTRAINT IF EXISTS sacrament_participants_source_check');
            DB::statement("
                ALTER TABLE sacrament_participants
                ADD CONSTRAINT sacrament_participants_source_check
                CHECK (source IN ('member','internal_leadership','external','unresolved'))
            ");
        }

        Schema::table('sacrament_participants', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropIndex('sacrament_participants_tenant_person_idx');
            $table->dropColumn('person_id');
        });

        Schema::table('sacraments', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropIndex('sacraments_tenant_person_idx');
            $table->dropColumn('person_id');
        });

        Schema::table('family_members', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
            $table->dropIndex(['person_id']);
            $table->dropColumn('person_id');
        });
    }
};
