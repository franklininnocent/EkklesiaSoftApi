<?php

namespace Modules\Authentication\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_is_platform_owner_role(): void
    {
        $role = Role::create([
            'name' => Role::SUPER_ADMIN,
            'description' => 'Owner',
            'level' => Role::LEVEL_SUPER_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $this->assertTrue($role->isPlatformRole());
        $this->assertFalse($role->isSupportRole());
        $this->assertTrue($role->isSuperAdmin());
    }

    public function test_support_admin_is_below_platform_ladder(): void
    {
        $role = Role::create([
            'name' => Role::SUPPORT_ADMIN,
            'description' => 'Support',
            'level' => Role::LEVEL_SUPPORT_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_SUPPORT,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $this->assertFalse($role->isPlatformRole());
        $this->assertTrue($role->isSupportRole());
        $this->assertSame(5, $role->level);
    }

    public function test_support_user_is_not_ekklesia_role_and_super_admin_bypasses_permissions(): void
    {
        $supportRole = Role::create([
            'name' => Role::SUPPORT_ADMIN,
            'level' => Role::LEVEL_SUPPORT_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_SUPPORT,
        ]);

        $supportUser = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $supportRole->id,
            'active' => 1,
        ]);
        $supportUser->syncRoles([$supportRole->id]);

        $this->assertFalse($supportUser->hasEkklesiaRole());
        $this->assertTrue($supportUser->hasRole(Role::SUPPORT_ADMIN));

        $ownerRole = Role::create([
            'name' => Role::SUPER_ADMIN,
            'level' => Role::LEVEL_SUPER_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $owner = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $ownerRole->id,
            'active' => 1,
        ]);
        $owner->syncRoles([$ownerRole->id]);

        $this->assertTrue($owner->isSuperAdmin());
        $this->assertTrue($owner->hasPermission('support.sessions.start'));
    }

    public function test_tenant_user_cannot_sync_support_admin_role(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $supportRole = Role::create([
            'name' => Role::SUPPORT_ADMIN,
            'level' => Role::LEVEL_SUPPORT_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_SUPPORT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $tenantRole->id,
            'active' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        $user->syncRoles([$supportRole->id]);
    }

    public function test_has_non_tenant_assignable_roles_detects_platform_and_support_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $supportRole = Role::create([
            'name' => Role::SUPPORT_ADMIN,
            'level' => Role::LEVEL_SUPPORT_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_SUPPORT,
        ]);

        $this->assertFalse(Role::hasNonTenantAssignableRoles([$tenantRole->id], $tenant->id));
        $this->assertTrue(Role::hasNonTenantAssignableRoles([$supportRole->id], $tenant->id));
    }
}
