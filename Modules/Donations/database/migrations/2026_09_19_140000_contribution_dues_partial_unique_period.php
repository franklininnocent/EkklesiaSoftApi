<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contribution_dues')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contribution_dues DROP CONSTRAINT IF EXISTS contribution_dues_unique_period');
            DB::statement('DROP INDEX IF EXISTS contribution_dues_unique_period');

            DB::statement(
                'CREATE UNIQUE INDEX contribution_dues_unique_period ON contribution_dues (tenant_id, plan_id, family_id, period_label) WHERE deleted_at IS NULL'
            );

            return;
        }

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropUnique('contribution_dues_unique_period');
            $table->unique(
                ['tenant_id', 'plan_id', 'family_id', 'period_label'],
                'contribution_dues_unique_period'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contribution_dues')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS contribution_dues_unique_period');

            DB::statement(
                'ALTER TABLE contribution_dues ADD CONSTRAINT contribution_dues_unique_period UNIQUE (tenant_id, plan_id, family_id, period_label)'
            );

            return;
        }

        Schema::table('contribution_dues', function (Blueprint $table): void {
            $table->dropUnique('contribution_dues_unique_period');
            $table->unique(
                ['tenant_id', 'plan_id', 'family_id', 'period_label'],
                'contribution_dues_unique_period'
            );
        });
    }
};
