<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Services\TenantRoleService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantRoleService $service;
    private Tenant $tenant;
    private User $tenantAdmin;
    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantRoleService::class);

        $this->tenant = Tenant::factory()->create();
        $this->adminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        ]);

        $this->tenantAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->adminRole->id,
        ]);
        $this->tenantAdmin->syncRoles([$this->adminRole->id]);
    }

    #[Test]
    public function it_creates_a_custom_tenant_role(): void
    {
        $role = $this->service->createTenantRole($this->tenantAdmin, [
            'name' => 'Liturgical Coordinator',
            'description' => 'Coordinates liturgy',
            'level' => 4,
        ]);

        $this->assertEquals($this->tenant->id, $role->tenant_id);
        $this->assertTrue($role->isCustom());
        $this->assertEquals(Role::ROLE_TYPE_TENANT, $role->role_type);
        $this->assertEquals(Role::CLASSIFICATION_CUSTOM, $role->role_classification);
    }

    #[Test]
    public function it_blocks_renaming_tenant_administrator_role(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Church Administrator role name cannot be changed.');

        $this->service->updateTenantRole($this->tenantAdmin, $this->adminRole, [
            'name' => 'Renamed Admin',
        ]);
    }

    #[Test]
    public function it_blocks_deleting_role_with_assigned_users(): void
    {
        $customRole = $this->service->createTenantRole($this->tenantAdmin, [
            'name' => 'Finance Assistant',
            'description' => 'Supports finance team',
            'level' => 5,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $customRole->id,
        ]);
        $user->syncRoles([$customRole->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete role with assigned users. Reassign users first.');

        $this->service->deleteTenantRole($this->tenantAdmin, $customRole);
    }

    #[Test]
    public function it_allows_updating_default_template_role(): void
    {
        $defaultRole = Role::create([
            'name' => 'Pastor',
            'description' => 'Tenant Pastor',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
            'role_classification' => Role::CLASSIFICATION_DEFAULT_TEMPLATE,
        ]);

        $updated = $this->service->updateTenantRole($this->tenantAdmin, $defaultRole, [
            'name' => 'Lead Pastor',
            'active' => 0,
        ]);

        $this->assertSame('Lead Pastor', $updated->name);
        $this->assertSame(0, $updated->active);
    }
}
