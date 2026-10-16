<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE contribution_plans MODIFY COLUMN frequency VARCHAR(30) NOT NULL DEFAULT 'monthly'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE contribution_plans ALTER COLUMN frequency TYPE VARCHAR(30)');
            DB::statement("ALTER TABLE contribution_plans ALTER COLUMN frequency SET DEFAULT 'monthly'");
            DB::statement('ALTER TABLE contribution_plans DROP CONSTRAINT IF EXISTS contribution_plans_frequency_check');
            DB::statement("ALTER TABLE contribution_plans ADD CONSTRAINT contribution_plans_frequency_check CHECK (frequency IN ('one_time','weekly','monthly','quarterly','half_yearly','yearly','custom'))");
        }
    }

    public function down(): void
    {
        // Column stays VARCHAR for backward compatibility.
    }
};
