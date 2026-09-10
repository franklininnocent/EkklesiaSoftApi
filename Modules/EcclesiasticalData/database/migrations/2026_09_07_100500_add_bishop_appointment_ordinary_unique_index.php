<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\EcclesiasticalData\Support\CanonicalRole;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('bishop_appointments')) {
            return;
        }

        $roles = implode("','", CanonicalRole::ordinaryRoles());

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS bishop_appt_one_current_ordinary_per_diocese
                ON bishop_appointments (diocese_id)
                WHERE is_current = true
                  AND deleted_at IS NULL
                  AND canonical_role IN ('{$roles}')
            ");

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS bishop_appt_one_current_ordinary_per_diocese
                ON bishop_appointments (diocese_id)
                WHERE is_current = 1
                  AND deleted_at IS NULL
                  AND canonical_role IN ('{$roles}')
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS bishop_appt_one_current_ordinary_per_diocese');
        }
    }
};
