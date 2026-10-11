<?php

namespace Modules\RolesAndPermissions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Database\Seeders\TenantPermissionCatalogSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Services\TenantPermissionCatalogService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnsupportedAttendancePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function runRetireAttendancePermissionsMigration(): void
    {
        $migration = require base_path(
            'Modules/RolesAndPermissions/database/migrations/2026_10_08_120000_retire_unsupported_attendance_permissions.php'
        );
        $migration->up();
    }

    #[Test]
    public function tenant_permission_catalog_seeder_does_not_create_attendance_permissions(): void
    {
        $this->seed(TenantPermissionCatalogSeeder::class);

        $this->assertDatabaseMissing('permissions', ['name' => 'attendance.view']);
        $this->assertSame(
            0,
            Permission::query()->where('name', 'like', 'attendance.%')->count()
        );
    }

    #[Test]
    public function retire_attendance_migration_soft_deletes_existing_attendance_permissions(): void
    {
        Permission::create([
            'name' => 'attendance.view',
            'display_name' => 'View Attendance',
            'description' => 'Legacy',
            'module' => 'Attendance',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'attendance',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->runRetireAttendancePermissionsMigration();

        $legacy = Permission::withTrashed()->where('name', 'attendance.view')->first();
        $this->assertNotNull($legacy);
        $this->assertSame(0, (int) $legacy->active);
        $this->assertNotNull($legacy->deleted_at);
        $this->assertSame(
            0,
            Permission::query()->where('name', 'like', 'attendance.%')->count()
        );
    }

    #[Test]
    public function tenant_permission_catalog_excludes_retired_attendance_permissions(): void
    {
        Permission::create([
            'name' => 'attendance.view',
            'display_name' => 'View Attendance',
            'description' => 'Legacy',
            'module' => 'Attendance',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'attendance',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->runRetireAttendancePermissionsMigration();
        $this->seed(TenantPermissionCatalogSeeder::class);

        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        $catalog = app(TenantPermissionCatalogService::class)->listForTenant($user, false);
        $this->assertFalse(
            $catalog->contains(fn ($permission) => str_starts_with((string) $permission->name, 'attendance.'))
        );
        $this->assertFalse(
            $catalog->contains(fn ($permission) => $permission->module === 'Attendance')
        );
    }

    #[Test]
    public function permissions_index_excludes_active_attendance_rows_for_tenant_users(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        Permission::create([
            'name' => 'attendance.view',
            'display_name' => 'View Attendance',
            'description' => 'Legacy active row',
            'module' => 'Attendance',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'attendance',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/permissions?per_page=all');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertFalse($names->contains('attendance.view'));
    }
}
