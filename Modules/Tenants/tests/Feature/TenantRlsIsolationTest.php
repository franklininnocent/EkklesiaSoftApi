<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantRlsManager;
use Tests\TestCase;

class TenantRlsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL RLS isolation tests require the pgsql driver.');
        }

        config(['tenants.isolation.rls_enabled' => true]);
    }

    public function test_wave_one_policies_exist(): void
    {
        $tables = collect(DB::select(
            "SELECT tablename FROM pg_policies WHERE policyname LIKE '%_tenant_isolation'"
        ))->pluck('tablename')->all();

        foreach (TenantRlsManager::WAVE_ONE_TABLES as $expectedTable) {
            $this->assertContains($expectedTable, $tables, "Missing RLS policy for {$expectedTable}");
        }
    }

    public function test_rls_fails_closed_without_tenant_guc_for_non_superuser(): void
    {
        if ($this->connectionIsPrivilegedForRlsBypass()) {
            $this->markTestSkipped('RLS row isolation requires a non-superuser, non-BYPASSRLS DB role.');
        }

        $tenant = Tenant::factory()->active()->create();

        Family::query()->create([
            'tenant_id' => $tenant->id,
            'family_name' => 'RLS Test Family',
            'status' => 'active',
        ]);

        $visible = DB::select('SELECT id FROM families');

        $this->assertCount(0, $visible);
    }

    public function test_rls_scopes_rows_to_transaction_local_tenant(): void
    {
        if ($this->connectionIsPrivilegedForRlsBypass()) {
            $this->markTestSkipped('RLS row isolation requires a non-superuser, non-BYPASSRLS DB role.');
        }

        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();

        $familyA = Family::query()->create([
            'tenant_id' => $tenantA->id,
            'family_name' => 'Tenant A Family',
            'status' => 'active',
        ]);

        Family::query()->create([
            'tenant_id' => $tenantB->id,
            'family_name' => 'Tenant B Family',
            'status' => 'active',
        ]);

        TenantRlsManager::runWithTenant($tenantA->id, function () use ($familyA): void {
            $rows = DB::select('SELECT id FROM families');

            $this->assertCount(1, $rows);
            $this->assertSame($familyA->id, $rows[0]->id);
        });
    }

    private function connectionIsPrivilegedForRlsBypass(): bool
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

        if ($role === null) {
            return true;
        }

        return (bool) $role->rolsuper || (bool) $role->rolbypassrls;
    }
}
