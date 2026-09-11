<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenantPermission;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureTenantPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_missing_permissions_for_tenant_user(): void
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
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);
        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, null));

        $request = Request::create('/api/tenant/roles', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantPermission();
        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'roles.view', 'permissions.view');

        $this->assertSame(403, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame('Insufficient permission for this tenant action.', $body['message']);
        $this->assertSame(['roles.view', 'permissions.view'], $body['missing_permissions']);
    }

    #[Test]
    public function it_allows_user_with_required_permission(): void
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
            'name' => 'roles.view',
            'display_name' => 'View Roles',
            'description' => 'View tenant roles',
            'module' => 'RolesAndPermissions',
            'scope' => Permission::SCOPE_TENANT,
            'category' => 'roles',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);
        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, null));

        $request = Request::create('/api/tenant/roles', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantPermission();
        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'roles.view');

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_allows_tenant_administrator_without_explicit_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => 'Administrator',
            'description' => 'Tenant administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);
        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, null));

        $request = Request::create('/api/tenant/bishop-updates/leadership', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantPermission();
        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'bishops.view');

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_blocks_when_tenant_context_is_missing(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        app()->instance(TenantContext::class, TenantContext::empty());

        $request = Request::create('/api/tenant/roles', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantPermission();
        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'roles.view');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Tenant context required.', $response->getData(true)['message']);
    }

    #[Test]
    public function it_allows_parish_home_platform_operator_during_owned_support_session(): void
    {
        $homeTenant = Tenant::factory()->create();
        $targetTenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::EKKLESIA_ADMIN,
            'description' => 'Ekklesia admin',
            'level' => Role::LEVEL_EKKLESIA_ADMIN,
            'active' => 1,
            'tenant_id' => null,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_PLATFORM,
        ]);
        $permission = Permission::create([
            'name' => 'support.sessions.standard',
            'display_name' => 'Support sessions (standard)',
            'description' => 'Standard support mode',
            'module' => 'SupportAccess',
            'scope' => Permission::SCOPE_PLATFORM,
            'category' => 'support',
            'tenant_id' => null,
            'is_custom' => false,
            'active' => 1,
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create(['tenant_id' => $homeTenant->id, 'role_id' => $role->id]);
        $user->syncRoles([$role->id]);

        $session = new ActiveSupportSession(
            id: 'support-session-test',
            tenantId: (int) $targetTenant->id,
            mode: SupportSessionMode::Standard,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'diagnosis',
        );
        app()->instance(TenantContext::class, TenantContext::fromUserAndSession($user, $session));

        $request = Request::create('/api/families/statistics', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantPermission();
        $response = $middleware->handle($request, fn () => new Response('ok', 200), 'families.view');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($user->hasPermission('support.sessions.standard'));
    }
}
