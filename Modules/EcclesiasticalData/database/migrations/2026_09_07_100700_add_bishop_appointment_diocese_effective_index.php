<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('bishop_appointments')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('
                CREATE INDEX IF NOT EXISTS bishop_appt_diocese_effective_idx
                ON bishop_appointments (diocese_id, effective_date DESC)
                WHERE deleted_at IS NULL
            ');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('
                CREATE INDEX IF NOT EXISTS bishop_appt_diocese_effective_idx
                ON bishop_appointments (diocese_id, effective_date)
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS bishop_appt_diocese_effective_idx');
        }
    }
};
