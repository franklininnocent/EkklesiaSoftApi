<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (app()->bound(TenantContext::class)) {
            app()->forgetInstance(TenantContext::class);
        }

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();

        $this->seedRolesAndPermissions();
    }

    protected function tearDown(): void
    {
        User::flushRequestPermissionCache();

        parent::tearDown();
    }

    #[Test]
    public function super_admin_can_access_health_endpoint(): void
    {
        $user = $this->createPlatformUser(Role::SUPER_ADMIN);

        Passport::actingAs($user);

        $this->getJson('/api/admin/application-access/health')
            ->assertOk()
            ->assertJsonPath('module', 'ApplicationAccess');
    }

    #[Test]
    public function tenant_user_is_denied_application_access(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->where('name', 'Administrator')->first()
            ?? Role::query()->create([
                'name' => 'Administrator',
                'description' => 'Tenant admin',
                'level' => 10,
                'active' => 1,
                'tenant_id' => $tenant->id,
                'role_type' => Role::ROLE_TYPE_TENANT,
            ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/admin/application-access/health')
            ->assertForbidden();
    }

    #[Test]
    public function ekklesia_admin_with_view_permission_can_access(): void
    {
        $user = $this->createPlatformUser(Role::EKKLESIA_ADMIN);

        Passport::actingAs($user);

        $this->getJson('/api/admin/application-access/health')
            ->assertOk();
    }

    #[Test]
    public function permission_migration_assigns_view_to_ekklesia_admin(): void
    {
        $permission = Permission::query()
            ->where('name', 'application_access.view')
            ->first();

        $this->assertNotNull($permission);

        $role = Role::query()->where('name', Role::EKKLESIA_ADMIN)->first();
        $this->assertNotNull($role);
        $this->assertTrue(
            $role->permissions()->where('permissions.name', 'application_access.view')->exists()
        );
    }

    private function seedRolesAndPermissions(): void
    {
        foreach ([
            [Role::SUPER_ADMIN, Role::LEVEL_SUPER_ADMIN],
            [Role::EKKLESIA_ADMIN, Role::LEVEL_EKKLESIA_ADMIN],
        ] as [$name, $level]) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $name,
                    'level' => $level,
                    'active' => 1,
                    'tenant_id' => null,
                    'role_type' => Role::ROLE_TYPE_PLATFORM,
                ]
            );
        }

        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
    }

    private function createPlatformUser(string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }
}
