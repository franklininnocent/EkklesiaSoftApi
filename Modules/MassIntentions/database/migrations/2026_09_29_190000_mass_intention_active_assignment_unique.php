<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX mass_intention_assignments_one_active_per_obligation
                ON mass_intention_assignments (obligation_id)
                WHERE unassigned_at IS NULL'
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX mass_intention_assignments_one_active_per_obligation
                ON mass_intention_assignments (obligation_id)
                WHERE unassigned_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mass_intention_assignments_one_active_per_obligation');
    }
};
