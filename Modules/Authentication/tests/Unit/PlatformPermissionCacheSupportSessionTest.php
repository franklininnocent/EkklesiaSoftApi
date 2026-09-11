<?php

namespace Modules\Authentication\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformPermissionCacheSupportSessionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function parish_home_operator_resolves_platform_permission_during_support_session_after_cache_warm(): void
    {
        $homeTenant = \Modules\Tenants\Models\Tenant::factory()->create();
        $role = Role::create([
            'name' => 'SupportAdmin',
            'description' => 'Support operator',
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
            'tenant_id' => $homeTenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, null));
        $user->clearPermissionsCache();
        User::flushRequestPermissionCache();
        $this->assertFalse($user->hasPermission('support.sessions.readonly'));

        $session = new ActiveSupportSession(
            id: 'cache-test-session',
            tenantId: (int) $homeTenant->id + 1,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'diagnosis',
        );
        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, $session));
        User::flushRequestPermissionCache();

        $this->assertTrue($user->hasPermission('support.sessions.readonly'));

        Cache::flush();
    }
}
