<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Tests\Concerns\InteractsWithTenantContext;
use Tests\TestCase;

class EnforcesTenantIsolationTraitTest extends TestCase
{
    use InteractsWithTenantContext;
    use RefreshDatabase;

    public function test_apply_tenant_isolation_uses_effective_tenant_not_home_tenant(): void
    {
        $homeTenant = Tenant::factory()->active()->create();
        $targetTenant = Tenant::factory()->active()->create();
        $supportUser = User::factory()->create(['tenant_id' => $homeTenant->id]);

        $targetPermission = Permission::query()->create([
            'name' => 'test.target.permission',
            'display_name' => 'Target',
            'module' => 'Test',
            'category' => 'test',
            'scope' => Permission::SCOPE_TENANT,
            'active' => 1,
            'tenant_id' => $targetTenant->id,
            'is_custom' => false,
        ]);

        $foreignPermission = Permission::query()->create([
            'name' => 'test.foreign.permission',
            'display_name' => 'Foreign',
            'module' => 'Test',
            'category' => 'test',
            'scope' => Permission::SCOPE_TENANT,
            'active' => 1,
            'tenant_id' => $homeTenant->id,
            'is_custom' => false,
        ]);

        $session = new ActiveSupportSession(
            id: 'support-session-test',
            tenantId: $targetTenant->id,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $supportUser->id,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'test',
        );

        $this->bindTenantContext($supportUser, null, $session);
        auth()->login($supportUser);

        $controller = new class
        {
            use \Modules\RolesAndPermissions\Traits\EnforcesTenantIsolation;

            public function visiblePermissionIds(): array
            {
                $query = Permission::query();
                $query = $this->applyTenantIsolation($query, auth()->user(), 'permissions');

                return $query->pluck('id')->all();
            }
        };

        $visible = $controller->visiblePermissionIds();

        $this->assertContains($targetPermission->id, $visible);
        $this->assertNotContains($foreignPermission->id, $visible);
    }
}
