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

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement("
            CREATE INDEX ma_organizations_tenant_status_active_idx
            ON ma_organizations (tenant_id, status)
            WHERE deleted_at IS NULL
        ");

        DB::statement('CREATE INDEX ma_organizations_name_trgm_idx ON ma_organizations USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX ma_organizations_code_trgm_idx ON ma_organizations USING gin (code gin_trgm_ops)');

        DB::statement("
            ALTER TABLE ma_organizations
            ADD CONSTRAINT ma_organizations_status_check
            CHECK (status IN ('active', 'inactive'))
        ");

        DB::statement("
            ALTER TABLE ma_organizations
            ADD CONSTRAINT ma_organizations_theme_color_check
            CHECK (theme_color IS NULL OR theme_color ~ '^#[0-9A-Fa-f]{6}$')
        ");

        DB::statement("
            ALTER TABLE ma_memberships
            ADD CONSTRAINT ma_memberships_source_integrity_check
            CHECK (
                (member_source = 'parish' AND family_member_id IS NOT NULL AND guest_member_id IS NULL)
                OR
                (member_source = 'guest' AND guest_member_id IS NOT NULL AND family_member_id IS NULL)
            )
        ");

        DB::statement("
            ALTER TABLE ma_memberships
            ADD CONSTRAINT ma_memberships_exit_date_check
            CHECK (exit_date IS NULL OR exit_date >= joined_date)
        ");

        DB::statement("
            ALTER TABLE ma_memberships
            ADD CONSTRAINT ma_memberships_current_interval_check
            CHECK (
                (is_current = true AND status = 'active' AND exit_date IS NULL)
                OR
                (is_current = false)
            )
        ");

        DB::statement("
            ALTER TABLE ma_leadership_terms
            ADD CONSTRAINT ma_leadership_terms_effective_dates_check
            CHECK (effective_to IS NULL OR effective_to >= effective_from)
        ");

        DB::statement("
            ALTER TABLE ma_leadership_terms
            ADD CONSTRAINT ma_leadership_terms_appointment_date_check
            CHECK (appointment_date <= effective_from)
        ");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ma_leadership_terms DROP CONSTRAINT IF EXISTS ma_leadership_terms_appointment_date_check');
        DB::statement('ALTER TABLE ma_leadership_terms DROP CONSTRAINT IF EXISTS ma_leadership_terms_effective_dates_check');

        DB::statement('ALTER TABLE ma_memberships DROP CONSTRAINT IF EXISTS ma_memberships_current_interval_check');
        DB::statement('ALTER TABLE ma_memberships DROP CONSTRAINT IF EXISTS ma_memberships_exit_date_check');
        DB::statement('ALTER TABLE ma_memberships DROP CONSTRAINT IF EXISTS ma_memberships_source_integrity_check');

        DB::statement('ALTER TABLE ma_organizations DROP CONSTRAINT IF EXISTS ma_organizations_theme_color_check');
        DB::statement('ALTER TABLE ma_organizations DROP CONSTRAINT IF EXISTS ma_organizations_status_check');

        DB::statement('DROP INDEX IF EXISTS ma_organizations_code_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS ma_organizations_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS ma_organizations_tenant_status_active_idx');
    }
};
