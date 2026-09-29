<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Harden Ministries & Associations integrity:
 * - Soft-delete-aware unique codes/names for orgs and taxonomies
 * - Allow suspended as a current membership status (aligned with API/UI)
 * - Unique active single-occupancy leadership (org + position)
 * - One current membership interval per person/org (active or suspended)
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        $this->replaceSoftDeleteUniques($driver);

        if ($driver === 'pgsql') {
            $this->hardenPostgresMembershipAndLeadership();
        }

        if ($driver === 'sqlite') {
            $this->hardenSqliteMembershipAndLeadership();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_uq');
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_parish');
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_guest');

            DB::statement('ALTER TABLE ma_memberships DROP CONSTRAINT IF EXISTS ma_memberships_current_interval_check');
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
                CREATE UNIQUE INDEX ma_memberships_uq_active_parish
                ON ma_memberships (tenant_id, organization_id, family_member_id)
                WHERE member_source = 'parish'
                  AND is_current = true
                  AND status = 'active'
                  AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE UNIQUE INDEX ma_memberships_uq_active_guest
                ON ma_memberships (tenant_id, organization_id, guest_member_id)
                WHERE member_source = 'guest'
                  AND is_current = true
                  AND status = 'active'
                  AND deleted_at IS NULL
            ");
            DB::statement("
                CREATE INDEX ma_leadership_terms_org_position_active_idx
                ON ma_leadership_terms (organization_id, position_id)
                WHERE status = 'active' AND deleted_at IS NULL
            ");
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_uq');
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_parish');
            DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_guest');
        }

        $this->restoreLegacyUniques($driver);
    }

    private function replaceSoftDeleteUniques(string $driver): void
    {
        $this->dropIndexOrConstraint($driver, 'ma_organizations', 'ma_organizations_tenant_id_code_unique');
        $this->dropIndexOrConstraint($driver, 'ma_organizations', 'ma_organizations_tenant_id_name_unique');
        $this->dropIndexOrConstraint($driver, 'ma_organization_categories', 'ma_organization_categories_tenant_id_code_unique');
        $this->dropIndexOrConstraint($driver, 'ma_organization_categories', 'ma_organization_categories_tenant_id_name_unique');
        $this->dropIndexOrConstraint($driver, 'ma_organization_types', 'ma_organization_types_tenant_id_code_unique');
        $this->dropIndexOrConstraint($driver, 'ma_positions', 'ma_positions_tenant_id_code_unique');

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ma_organizations_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organizations_tenant_name_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_categories_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_categories_tenant_name_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_types_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_positions_tenant_code_alive_uq');

        DB::statement('
            CREATE UNIQUE INDEX ma_organizations_tenant_code_alive_uq
            ON ma_organizations (tenant_id, code)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX ma_organizations_tenant_name_alive_uq
            ON ma_organizations (tenant_id, name)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX ma_organization_categories_tenant_code_alive_uq
            ON ma_organization_categories (tenant_id, code)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX ma_organization_categories_tenant_name_alive_uq
            ON ma_organization_categories (tenant_id, name)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX ma_organization_types_tenant_code_alive_uq
            ON ma_organization_types (tenant_id, code)
            WHERE deleted_at IS NULL
        ');
        DB::statement('
            CREATE UNIQUE INDEX ma_positions_tenant_code_alive_uq
            ON ma_positions (tenant_id, code)
            WHERE deleted_at IS NULL
        ');
    }

    private function restoreLegacyUniques(string $driver): void
    {
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ma_organizations_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organizations_tenant_name_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_categories_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_categories_tenant_name_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_organization_types_tenant_code_alive_uq');
        DB::statement('DROP INDEX IF EXISTS ma_positions_tenant_code_alive_uq');

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX ma_organizations_tenant_id_code_unique ON ma_organizations (tenant_id, code)');
            DB::statement('CREATE UNIQUE INDEX ma_organizations_tenant_id_name_unique ON ma_organizations (tenant_id, name)');
            DB::statement('CREATE UNIQUE INDEX ma_organization_categories_tenant_id_code_unique ON ma_organization_categories (tenant_id, code)');
            DB::statement('CREATE UNIQUE INDEX ma_organization_categories_tenant_id_name_unique ON ma_organization_categories (tenant_id, name)');
            DB::statement('CREATE UNIQUE INDEX ma_organization_types_tenant_id_code_unique ON ma_organization_types (tenant_id, code)');
            DB::statement('CREATE UNIQUE INDEX ma_positions_tenant_id_code_unique ON ma_positions (tenant_id, code)');
        }
    }

    private function hardenPostgresMembershipAndLeadership(): void
    {
        DB::statement('ALTER TABLE ma_memberships DROP CONSTRAINT IF EXISTS ma_memberships_current_interval_check');
        DB::statement("
            ALTER TABLE ma_memberships
            ADD CONSTRAINT ma_memberships_current_interval_check
            CHECK (
                (is_current = true AND status IN ('active', 'suspended') AND exit_date IS NULL)
                OR
                (is_current = false)
            )
        ");

        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_active_parish');
        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_active_guest');
        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_parish');
        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_guest');

        DB::statement("
            CREATE UNIQUE INDEX ma_memberships_uq_current_parish
            ON ma_memberships (tenant_id, organization_id, family_member_id)
            WHERE member_source = 'parish'
              AND is_current = true
              AND deleted_at IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX ma_memberships_uq_current_guest
            ON ma_memberships (tenant_id, organization_id, guest_member_id)
            WHERE member_source = 'guest'
              AND is_current = true
              AND deleted_at IS NULL
        ");

        DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_idx');
        DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_uq');
        DB::statement("
            CREATE UNIQUE INDEX ma_leadership_terms_org_position_active_uq
            ON ma_leadership_terms (organization_id, position_id)
            WHERE status = 'active' AND deleted_at IS NULL
        ");
    }

    private function hardenSqliteMembershipAndLeadership(): void
    {
        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_parish');
        DB::statement('DROP INDEX IF EXISTS ma_memberships_uq_current_guest');
        DB::statement('DROP INDEX IF EXISTS ma_leadership_terms_org_position_active_uq');

        DB::statement("
            CREATE UNIQUE INDEX ma_memberships_uq_current_parish
            ON ma_memberships (tenant_id, organization_id, family_member_id)
            WHERE member_source = 'parish'
              AND is_current = 1
              AND deleted_at IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX ma_memberships_uq_current_guest
            ON ma_memberships (tenant_id, organization_id, guest_member_id)
            WHERE member_source = 'guest'
              AND is_current = 1
              AND deleted_at IS NULL
        ");
        DB::statement("
            CREATE UNIQUE INDEX ma_leadership_terms_org_position_active_uq
            ON ma_leadership_terms (organization_id, position_id)
            WHERE status = 'active' AND deleted_at IS NULL
        ");
    }

    private function dropIndexOrConstraint(string $driver, string $table, string $name): void
    {
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("DROP INDEX IF EXISTS {$name}");

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
