<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Policies\PermissionPolicy;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PermissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantUser(Tenant $tenant): User
    {
        $role = Role::create([
            'name' => 'Tenant Manager',
            'description' => 'Tenant Manager',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);

        return $user;
    }

    #[Test]
    public function tenant_user_cannot_view_platform_scoped_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createTenantUser($tenant);
        $platformPermission = Permission::create([
            'name' => 'tenants.create',
            'display_name' => 'Create Tenants',
            'description' => 'Platform',
            'module' => 'Tenants',
            'scope' => Permission::SCOPE_PLATFORM,
            'category' => 'tenants',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $policy = new PermissionPolicy();
        $this->assertFalse($policy->view($user, $platformPermission));
    }

    #[Test]
    public function tenant_user_can_view_global_or_same_tenant_permission_when_not_platform_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->createTenantUser($tenant);

        $globalTenantScopedPermission = Permission::create([
            'name' => 'members.view',
            'display_name' => 'View Members',
            'description' => 'Global tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $sameTenantCustomPermission = Permission::create([
            'name' => 'custom.same',
            'display_name' => 'Custom Same',
            'description' => 'Tenant permission',
            'module' => 'Custom',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'custom',
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'active' => 1,
        ]);
        $otherTenantCustomPermission = Permission::create([
            'name' => 'custom.other',
            'display_name' => 'Custom Other',
            'description' => 'Tenant permission',
            'module' => 'Custom',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'custom',
            'tenant_id' => $otherTenant->id,
            'is_custom' => true,
            'active' => 1,
        ]);

        $policy = new PermissionPolicy();
        $this->assertTrue($policy->view($user, $globalTenantScopedPermission));
        $this->assertTrue($policy->view($user, $sameTenantCustomPermission));
        $this->assertFalse($policy->view($user, $otherTenantCustomPermission));
    }
}
