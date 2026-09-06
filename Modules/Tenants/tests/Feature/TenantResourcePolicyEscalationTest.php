<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\PastoralCare\Support\PastoralCareType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantResourcePolicyEscalationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cross_tenant_family_show_returns_not_found(): void
    {
        $tenantA = Tenant::factory()->active()->create();
        $tenantB = Tenant::factory()->active()->create();
        $user = $this->staffWithPermissions($tenantA, ['families.view']);
        $foreignFamily = Family::factory()->create(['tenant_id' => $tenantB->id]);

        Passport::actingAs($user);

        $this->getJson('/api/families/'.$foreignFamily->id)->assertNotFound();
    }

    #[Test]
    public function viewer_cannot_update_family_even_when_route_is_reached(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $user = $this->staffWithPermissions($tenant, ['families.view']);
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

        Passport::actingAs($user);

        $this->putJson('/api/families/'.$family->id, [
            'family_name' => 'Should Not Update',
        ])->assertForbidden();
    }

    #[Test]
    public function pastoral_viewer_cannot_assign_requests(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $admin = $this->staffWithPermissions($tenant, [
            'pastoral.care.view',
            'pastoral.care.create',
            'pastoral.care.assign',
        ], 'Pastoral Admin');
        $viewer = $this->staffWithPermissions($tenant, ['pastoral.care.view'], 'Pastoral Viewer');
        $assignee = User::factory()->create(['tenant_id' => $tenant->id, 'active' => 1]);
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

        Passport::actingAs($admin);

        $requestId = $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $family->id,
            'type' => PastoralCareType::HOSPITAL_VISIT,
            'summary' => 'Needs follow-up',
        ])->assertCreated()->json('data.id');

        Passport::actingAs($viewer);

        $this->postJson('/api/tenant/pastoral/requests/'.$requestId.'/assign', [
            'assigned_to_user_id' => $assignee->id,
        ])->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffWithPermissions(Tenant $tenant, array $permissions, string $roleName = 'Escalation Viewer'): User
    {
        $role = Role::create([
            'name' => $roleName,
            'description' => 'View-only escalation test role',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Escalation test permission',
                    'module' => 'Test',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'test',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }
}
