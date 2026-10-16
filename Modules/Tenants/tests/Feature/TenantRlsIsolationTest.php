<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\Family;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantRlsManager;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class TenantRlsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            if (filter_var(env('CYCLE3_PG_REQUIRED', false), FILTER_VALIDATE_BOOL)) {
                $this->fail('PostgreSQL RLS isolation tests require the pgsql driver (CYCLE3_PG_REQUIRED is set).');
            }

            $this->markTestSkipped('PostgreSQL RLS isolation tests require the pgsql driver.');
        }

        config(['tenants.isolation.rls_enabled' => true]);
        $this->ensureRlsTestRolePrivileges();
        $this->assumeNonPrivilegedRole();
    }

    public function test_wave_one_policies_exist(): void
    {
        $this->assertSame([], TenantRlsManager::missingWaveOnePolicyTables());
    }

    public function test_rls_fails_closed_without_tenant_guc_for_non_superuser(): void
    {
        $this->requireNonPrivilegedSession();

        $this->asTableOwner(function (): void {
            $tenant = Tenant::factory()->active()->create();
            Family::query()->create([
                'tenant_id' => $tenant->id,
                'family_name' => 'RLS Test Family',
                'status' => 'active',
            ]);
        });

        $visible = DB::select('SELECT id FROM families');

        $this->assertCount(0, $visible);
    }

    public function test_rls_scopes_rows_to_transaction_local_tenant(): void
    {
        $this->requireNonPrivilegedSession();

        $familyA = $this->asTableOwner(function () {
            $tenantA = Tenant::factory()->active()->create();
            $tenantB = Tenant::factory()->active()->create();

            $created = Family::query()->create([
                'tenant_id' => $tenantA->id,
                'family_name' => 'Tenant A Family',
                'status' => 'active',
            ]);

            Family::query()->create([
                'tenant_id' => $tenantB->id,
                'family_name' => 'Tenant B Family',
                'status' => 'active',
            ]);

            return $created;
        });

        TenantRlsManager::runWithTenant($familyA->tenant_id, function () use ($familyA): void {
            $rows = DB::select('SELECT id FROM families');

            $this->assertCount(1, $rows);
            $this->assertSame($familyA->id, $rows[0]->id);
        });
    }

    private function ensureRlsTestRolePrivileges(): void
    {
        $role = (string) env('TENANT_RLS_TEST_ROLE', '');
        if ($role === '' || preg_match('/^[a-zA-Z0-9_]+$/', $role) !== 1) {
            return;
        }

        DB::unprepared('RESET ROLE');
        DB::unprepared('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.$role);
        DB::unprepared('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$role);
    }

    private function assumeNonPrivilegedRole(): void
    {
        $role = (string) env('TENANT_RLS_TEST_ROLE', '');
        if ($role !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $role) === 1) {
            $exists = DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]);
            if ($exists !== null) {
                DB::unprepared('SET ROLE '.$role);
            }
        }

        if ($this->connectionIsPrivilegedForRlsBypass()
            && filter_var(env('TENANT_RLS_REQUIRE_NONPRIVILEGED', false), FILTER_VALIDATE_BOOL)) {
            $this->fail('RLS isolation requires a non-superuser, non-BYPASSRLS DB role. Skips are treated as failure in this job.');
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function asTableOwner(callable $callback): mixed
    {
        DB::unprepared('RESET ROLE');

        try {
            return $callback();
        } finally {
            $this->assumeNonPrivilegedRole();
        }
    }

    private function requireNonPrivilegedSession(): void
    {
        if ($this->connectionIsPrivilegedForRlsBypass()) {
            if (filter_var(env('TENANT_RLS_REQUIRE_NONPRIVILEGED', false), FILTER_VALIDATE_BOOL)) {
                $this->fail('RLS row isolation requires a non-superuser, non-BYPASSRLS DB role.');
            }

            $this->markTestSkipped('RLS row isolation requires a non-superuser, non-BYPASSRLS DB role.');
        }
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
