<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE INDEX IF NOT EXISTS ma_memberships_tenant_current_status_alive_idx
            ON ma_memberships (tenant_id, is_current, status)
            WHERE deleted_at IS NULL
        ');

        DB::statement('
            CREATE INDEX IF NOT EXISTS ma_leadership_terms_tenant_status_effective_to_alive_idx
            ON ma_leadership_terms (tenant_id, status, effective_to)
            WHERE deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ma_memberships_tenant_current_status_alive_idx');
        DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_tenant_status_effective_to_alive_idx');
    }
};
