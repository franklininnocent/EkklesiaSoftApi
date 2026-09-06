<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Support\TenantRlsManager;

/**
 * Wave-1 PostgreSQL RLS policies (fail-closed when app.current_tenant is unset).
 *
 * Production should use a non-owner application DB role so policies apply.
 * FORCE ROW LEVEL SECURITY is intentionally omitted so migration/owner DDL still works.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (TenantRlsManager::WAVE_ONE_TABLES as $table) {
            if (! $this->tableExists($table) || ! $this->columnExists($table, 'tenant_id')) {
                continue;
            }

            $policy = $this->policyName($table);
            $using = TenantRlsManager::policySql($table);

            DB::unprepared(sprintf(
                'ALTER TABLE %s ENABLE ROW LEVEL SECURITY;',
                $this->quoteIdentifier($table)
            ));

            DB::unprepared(sprintf(
                'DROP POLICY IF EXISTS %s ON %s;',
                $this->quoteIdentifier($policy),
                $this->quoteIdentifier($table)
            ));

            DB::unprepared(sprintf(
                'CREATE POLICY %s ON %s FOR ALL USING (%s) WITH CHECK (%s);',
                $this->quoteIdentifier($policy),
                $this->quoteIdentifier($table),
                $using,
                $using
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (TenantRlsManager::WAVE_ONE_TABLES as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $policy = $this->policyName($table);

            DB::unprepared(sprintf(
                'DROP POLICY IF EXISTS %s ON %s;',
                $this->quoteIdentifier($policy),
                $this->quoteIdentifier($table)
            ));

            DB::unprepared(sprintf(
                'ALTER TABLE %s DISABLE ROW LEVEL SECURITY;',
                $this->quoteIdentifier($table)
            ));
        }
    }

    private function policyName(string $table): string
    {
        return $table.'_tenant_isolation';
    }

    private function tableExists(string $table): bool
    {
        $result = DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
            [$table]
        );

        return $result !== null;
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = DB::selectOne(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $result !== null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
};
