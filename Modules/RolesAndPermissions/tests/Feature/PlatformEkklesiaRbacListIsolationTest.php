<?php

namespace Modules\RolesAndPermissions\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformEkklesiaRbacListIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Role $tenantARole;

    private Role $tenantBRole;

    private Permission $tenantACustomPermission;

    private Permission $tenantBCustomPermission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['name' => 'Parish Alpha']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Parish Beta']);

        $this->tenantARole = Role::create([
            'name' => 'Alpha Pastor',
            'description' => 'Tenant A role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantBRole = Role::create([
            'name' => 'Beta Pastor',
            'description' => 'Tenant B role',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenantB->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantACustomPermission = Permission::create([
            'name' => 'alpha.custom.permission',
            'display_name' => 'Alpha Custom',
            'description' => 'Tenant A custom permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => $this->tenantA->id,
            'is_custom' => true,
            'active' => 1,
        ]);

        $this->tenantBCustomPermission = Permission::create([
            'name' => 'beta.custom.permission',
            'display_name' => 'Beta Custom',
            'description' => 'Tenant B custom permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => $this->tenantB->id,
            'is_custom' => true,
            'active' => 1,
        ]);
    }

    #[Test]
    public function ekklesia_admin_without_parish_home_does_not_see_tenant_roles_on_platform_endpoint(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);

        Passport::actingAs($user->fresh(['role']));

        $response = $this->getJson('/api/roles?per_page=all');

        $response->assertOk();
        $response->assertJsonMissing(['name' => $this->tenantARole->name]);
        $response->assertJsonMissing(['name' => $this->tenantBRole->name]);
    }

    #[Test]
    public function ekklesia_manager_without_parish_home_does_not_see_tenant_custom_permissions_on_platform_endpoint(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_MANAGER, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Manager',
                'level' => Role::LEVEL_EKKLESIA_MANAGER,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);

        Passport::actingAs($user->fresh(['role']));

        $response = $this->getJson('/api/permissions?per_page=all');

        $response->assertOk();
        $response->assertJsonMissing(['name' => $this->tenantACustomPermission->name]);
        $response->assertJsonMissing(['name' => $this->tenantBCustomPermission->name]);
    }

    #[Test]
    public function ekklesia_user_without_parish_home_sees_only_global_roles(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_USER, 'tenant_id' => null],
            [
                'description' => 'Ekklesia User',
                'level' => Role::LEVEL_EKKLESIA_USER,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_MANAGER, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Manager',
                'level' => Role::LEVEL_EKKLESIA_MANAGER,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);

        Passport::actingAs($user->fresh());

        $response = $this->getJson('/api/roles?per_page=all');

        $response->assertOk();
        $response->assertJsonFragment(['name' => Role::EKKLESIA_MANAGER]);
        $response->assertJsonMissing(['name' => $this->tenantARole->name]);
        $response->assertJsonMissing(['name' => $this->tenantBRole->name]);
    }
}
