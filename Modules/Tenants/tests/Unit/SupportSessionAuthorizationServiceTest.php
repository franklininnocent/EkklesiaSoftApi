<?php

namespace Modules\Tenants\Tests\Unit;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SupportSessionAuthorizationServiceTest extends TestCase
{
    #[Test]
    public function it_grants_tenant_product_access_for_owned_readonly_session(): void
    {
        $user = $this->supportOperator();
        $session = new ActiveSupportSession(
            id: 'sess-auth-1',
            tenantId: 42,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'diagnosis',
        );

        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, $session));

        $service = app(SupportSessionAuthorizationService::class);

        $this->assertTrue($service->grantsTenantProductAccess($user));
    }

    #[Test]
    public function it_denies_without_elevated_support_context(): void
    {
        $user = $this->supportOperator();

        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, null));

        $this->assertFalse(app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user));
    }

    #[Test]
    public function it_asserts_event_session_binding_when_elevated(): void
    {
        $user = $this->supportOperator();
        $session = new ActiveSupportSession(
            id: 'sess-auth-3',
            tenantId: 42,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'diagnosis',
        );

        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, $session));

        $service = app(SupportSessionAuthorizationService::class);
        $service->assertEventSessionBinding('sess-auth-3');

        $this->expectException(RuntimeException::class);
        $service->assertEventSessionBinding('other-session');
    }

    private function supportOperator(): User
    {
        $role = Role::create([
            'name' => 'SupportAdmin',
            'description' => 'Support admin',
            'level' => Role::LEVEL_EKKLESIA_MANAGER,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);

        $permission = Permission::create([
            'name' => 'support.sessions.readonly',
            'display_name' => 'Support readonly',
            'description' => 'Readonly support',
            'module' => 'SupportAccess',
            'scope' => Permission::SCOPE_PLATFORM,
            'category' => 'support',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        return $user->fresh(['role', 'roles']);
    }
}
