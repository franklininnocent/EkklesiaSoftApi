<?php

namespace Modules\RolesAndPermissions\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenant;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureTenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_blocks_user_without_tenant_context(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $request = Request::create('/api/tenant/roles', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenant();
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Tenant context required.', $response->getData(true)['message']);
    }

    #[Test]
    public function it_allows_user_with_tenant_context(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $request = Request::create('/api/tenant/roles', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = new EnsureTenant();
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
