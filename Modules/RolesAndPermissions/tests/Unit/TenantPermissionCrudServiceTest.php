<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Services\TenantPermissionCrudService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantPermissionCrudServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantPermissionCrudService $service;
    private Tenant $tenant;
    private User $tenantAdmin;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantPermissionCrudService::class);

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
        $this->tenantAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->adminRole->id,
        ]);
        $this->tenantAdmin->syncRoles([$this->adminRole->id]);
    }

    #[Test]
    public function it_creates_tenant_custom_permission(): void
    {
        $permission = $this->service->createTenantPermission($this->tenantAdmin, [
            'name' => 'pastoral.visits.manage',
            'display_name' => 'Manage Pastoral Visits',
            'description' => 'Create and manage pastoral visits',
            'module' => 'PastoralCare',
            'category' => 'pastoral',
        ]);

        $this->assertEquals($this->tenant->id, $permission->tenant_id);
        $this->assertTrue($permission->isCustom());
        $this->assertEquals(Permission::SCOPE_TENANT, $permission->scope);
    }

    #[Test]
    public function it_blocks_updating_system_permission_from_tenant_context(): void
    {
        $systemPermission = Permission::create([
            'name' => 'members.view',
            'display_name' => 'View Members',
            'description' => 'System tenant permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Permission does not belong to your tenant.');

        $this->service->updateTenantPermission($this->tenantAdmin, $systemPermission, [
            'description' => 'Updated',
        ]);
    }
}
