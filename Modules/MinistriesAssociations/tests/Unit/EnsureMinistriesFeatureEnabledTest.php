<?php

namespace Modules\MinistriesAssociations\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\MinistriesAssociations\Http\Middleware\EnsureMinistriesFeatureEnabled;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureMinistriesFeatureEnabledTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rejects_platform_user_without_tenant_context(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => 2,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, null)
        );

        $request = Request::create('/api/tenant/ministries/organizations', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(EnsureMinistriesFeatureEnabled::class);
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(403, $response->getStatusCode());
        $body = method_exists($response, 'getData') ? $response->getData(true) : [];
        $this->assertSame('Tenant context is required.', $body['message'] ?? null);
    }

    #[Test]
    public function allows_support_session_when_feature_enabled_for_effective_tenant(): void
    {
        $tenant = $this->createEnabledMinistriesTenant();

        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => 2,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        $session = new ActiveSupportSession(
            id: '11111111-1111-1111-1111-111111111111',
            tenantId: (int) $tenant->id,
            mode: SupportSessionMode::Standard,
            supportUserId: (int) $user->id,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );

        $request = Request::create('/api/tenant/ministries/organizations', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(EnsureMinistriesFeatureEnabled::class);
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allows_tenant_admin_home_context_when_feature_enabled(): void
    {
        $tenant = $this->createEnabledMinistriesTenant();

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, null)
        );

        $request = Request::create('/api/tenant/ministries/organizations', 'GET');
        $request->setUserResolver(fn () => $user);

        $middleware = app(EnsureMinistriesFeatureEnabled::class);
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function createEnabledMinistriesTenant(): Tenant
    {
        return Tenant::factory()->create([
            'features' => ['ministries_associations'],
            'active' => 1,
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'subscription_suspended_at' => null,
        ]);
    }
}
