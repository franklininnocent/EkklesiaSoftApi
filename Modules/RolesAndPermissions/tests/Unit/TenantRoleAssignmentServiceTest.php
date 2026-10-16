<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Services\TenantRoleAssignmentService;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantRoleAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantRoleAssignmentService $service;
    private Tenant $tenant;
    private Role $adminRole;
    private Role $pastorRole;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TenantRoleAssignmentService::class);

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
    public function it_blocks_removing_last_tenant_administrator(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove the last Church Administrator from this tenant.');

        $this->service->syncUserRoles($this->tenantAdmin, $this->tenantAdmin, [$this->pastorRole->id]);
    }

    #[Test]
    public function it_updates_legacy_role_id_to_lowest_level_assigned_role(): void
    {
        $targetUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->pastorRole->id,
        ]);
        $targetUser->syncRoles([$this->pastorRole->id]);

        $updated = $this->service->syncUserRoles(
            $this->tenantAdmin,
            $targetUser,
            [$this->adminRole->id, $this->pastorRole->id]
        );

        $this->assertEquals($this->adminRole->id, $updated->role_id);
        $this->assertCount(2, $updated->roles);
    }

    #[Test]
    public function it_blocks_assigning_role_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $foreignRole = Role::create([
            'name' => 'Foreign Role',
            'description' => 'Role from different tenant',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $otherTenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $targetUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->pastorRole->id,
        ]);
        $targetUser->syncRoles([$this->pastorRole->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One or more selected roles are invalid for your tenant.');

        $this->service->syncUserRoles($this->tenantAdmin, $targetUser, [$foreignRole->id]);
    }
}
