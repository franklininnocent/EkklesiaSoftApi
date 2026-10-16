<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $allowedFrequencies = [
        'one_time',
        'weekly',
        'monthly',
        'quarterly',
        'half_yearly',
        'yearly',
        'custom',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE contribution_plans DROP CONSTRAINT IF EXISTS contribution_plans_frequency_check');
        $allowed = implode("','", $this->allowedFrequencies);
        DB::statement("ALTER TABLE contribution_plans ADD CONSTRAINT contribution_plans_frequency_check CHECK (frequency IN ('{$allowed}'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE contribution_plans DROP CONSTRAINT IF EXISTS contribution_plans_frequency_check');
        $legacy = implode("','", ['weekly', 'monthly', 'quarterly', 'yearly']);
        DB::statement("ALTER TABLE contribution_plans ADD CONSTRAINT contribution_plans_frequency_check CHECK (frequency IN ('{$legacy}'))");
    }
};
