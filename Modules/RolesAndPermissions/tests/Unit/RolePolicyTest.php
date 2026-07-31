<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Policies\RolePolicy;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantUserWithPermissions(Tenant $tenant, array $permissions = []): User
    {
        $roleName = 'Tenant Manager ' . uniqid();
        $role = Role::create([
            'name' => $roleName,
            'description' => 'Tenant Manager',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionIds = [];
        foreach ($permissions as $permissionName) {
            $permission = Permission::updateOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'description' => 'Policy test permission',
                    'module' => 'RolesAndPermissions',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'roles',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);

        return $user;
    }

    #[Test]
    public function view_all_requires_roles_view_or_tenant_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createTenantUserWithPermissions($tenant, []);

        $policy = new RolePolicy();
        $this->assertFalse($policy->viewAny($user));

        $withPermission = $this->createTenantUserWithPermissions($tenant, ['roles.view']);
        $this->assertTrue($policy->viewAny($withPermission));
    }

    #[Test]
    public function update_requires_same_tenant_and_roles_update_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => 'Pastor',
            'description' => 'Pastor',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $foreignRole = Role::create([
            'name' => 'Foreign Pastor',
            'description' => 'Pastor',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $otherTenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $policy = new RolePolicy();
        $withoutPermission = $this->createTenantUserWithPermissions($tenant, []);
        $withPermission = $this->createTenantUserWithPermissions($tenant, ['roles.update']);

        $this->assertFalse($policy->update($withoutPermission, $role));
        $this->assertTrue($policy->update($withPermission, $role));
        $this->assertFalse($policy->update($withPermission, $foreignRole));
    }
}
