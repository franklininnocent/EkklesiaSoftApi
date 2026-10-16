<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantLogoAuthorization;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantLogoAuthorizationTest extends TestCase
{
    #[Test]
    public function platform_ekklesia_roles_can_view_any_tenant_logo(): void
    {
        $tenant = Tenant::factory()->active()->create();

        foreach ([Role::SUPER_ADMIN, Role::EKKLESIA_ADMIN, Role::EKKLESIA_MANAGER, Role::EKKLESIA_USER] as $roleName) {
            $role = Role::query()->where('name', $roleName)->first();
            if ($role === null) {
                $this->markTestSkipped("Role {$roleName} is not seeded.");
            }

            $user = User::factory()->create([
                'tenant_id' => null,
                'role_id' => $role->id,
            ]);

            $this->assertTrue(TenantLogoAuthorization::canView($tenant->id, $user));
        }
    }

    #[Test]
    public function parish_user_can_only_view_own_tenant_logo(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $otherTenant = Tenant::factory()->active()->create();
        $user = User::factory()->tenantUser($tenant->id)->create();

        $this->assertTrue(TenantLogoAuthorization::canView($tenant->id, $user));
        $this->assertFalse(TenantLogoAuthorization::canView($otherTenant->id, $user));
    }
}
