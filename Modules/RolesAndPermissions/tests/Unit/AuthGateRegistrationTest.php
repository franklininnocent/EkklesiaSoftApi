<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthGateRegistrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dynamic_permission_gate_uses_user_permission_check(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => 'Pastor',
            'description' => 'Pastor',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $permission = Permission::create([
            'name' => 'members.view',
            'display_name' => 'View Members',
            'description' => 'Gate test permission',
            'module' => 'Members',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'members',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $role->permissions()->sync([$permission->id]);

        $allowedUser = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $allowedUser->syncRoles([$role->id]);
        Passport::actingAs($allowedUser);
        (new \App\Providers\AuthServiceProvider($this->app))->boot();
        $this->assertTrue(Gate::forUser($allowedUser)->allows('members.view'));

        $deniedUser = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $deniedUser->syncRoles([$role->id]);
        $role->permissions()->sync([]);
        (new \App\Providers\AuthServiceProvider($this->app))->boot();
        $this->assertFalse(Gate::forUser($deniedUser)->allows('members.view'));
    }
}
