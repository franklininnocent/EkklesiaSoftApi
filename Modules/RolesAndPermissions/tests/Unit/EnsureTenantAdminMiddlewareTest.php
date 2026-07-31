<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenantAdmin;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureTenantAdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_non_tenant_admin_user(): void
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

        $request = Request::create('/api/tenant/roles', 'POST');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantAdmin();
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Tenant administrator access required.', $response->getData(true)['message']);
    }

    #[Test]
    public function it_allows_tenant_administrator_user(): void
    {
        $tenant = Tenant::factory()->create();
        $adminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $adminRole->id]);
        $user->syncRoles([$adminRole->id]);

        $request = Request::create('/api/tenant/roles', 'POST');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenantAdmin();
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
