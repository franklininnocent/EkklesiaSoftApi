<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Services\TenantPermissionService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantPermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantPermissionService $service;
    private Tenant $tenant;
    private Role $adminRole;
    private Role $pastorRole;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantPermissionService::class);

        $this->tenant = Tenant::factory()->create();
        $this->adminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->pastorRole = Role::create([
            'name' => 'Pastor',
            'description' => 'Pastor',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->adminRole->id,
        ]);
        $this->tenantAdmin->syncRoles([$this->adminRole->id]);
    }

    #[Test]
    public function it_blocks_platform_scope_permission_assignment(): void
    {
        $platformPermission = Permission::create([
            'name' => 'tenants.create',
            'display_name' => 'Create Tenants',
            'description' => 'Platform only',
            'module' => 'Tenants',
            'scope' => Permission::SCOPE_PLATFORM,
            'category' => 'tenants',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('platform-only');

        $this->service->syncRolePermissions($this->tenantAdmin, $this->pastorRole, [$platformPermission->id]);
    }

    #[Test]
    public function it_blocks_permission_escalation_when_actor_lacks_permission(): void
    {
        $existingPermission = Permission::create([
            'name' => 'members.view',
            'display_name' => 'View Members',
            'description' => 'Tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $extraPermission = Permission::create([
            'name' => 'members.edit',
            'display_name' => 'Edit Members',
            'description' => 'Tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->adminRole->permissions()->sync([$existingPermission->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission escalation blocked');

        $this->service->syncRolePermissions($this->tenantAdmin, $this->pastorRole, [$extraPermission->id]);
    }
}
