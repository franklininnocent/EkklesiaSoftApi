<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Family\app\Services\PersonBackfillService;

/**
 * One Person per FamilyMember (no merge), then require family_members.person_id.
 * One active membership per person is a current parish business rule, not a Person invariant.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PersonBackfillService::class)->run();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE family_members ALTER COLUMN person_id SET NOT NULL');
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS family_members_active_person_unique
                ON family_members (person_id)
                WHERE deleted_at IS NULL
            ');
        } else {
            Schema::table('family_members', function (Blueprint $table) {
                $table->unique('person_id', 'family_members_active_person_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS family_members_active_person_unique');
            DB::statement('ALTER TABLE family_members ALTER COLUMN person_id DROP NOT NULL');
        } else {
            Schema::table('family_members', function (Blueprint $table) {
                $table->dropUnique('family_members_active_person_unique');
                $table->uuid('person_id')->nullable()->change();
            });
        }
    }
};
