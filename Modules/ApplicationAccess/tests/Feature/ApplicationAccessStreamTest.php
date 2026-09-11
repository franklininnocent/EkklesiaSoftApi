<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamConnectionManager;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'applicationaccess.telemetry_enabled' => true,
            'applicationaccess.sse.ttl_seconds' => 5,
            'applicationaccess.sse.heartbeat_seconds' => 20,
            'applicationaccess.sse.poll_interval_ms' => 0,
            'applicationaccess.sse.max_connections' => 10,
        ]);

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();
        Cache::flush();

        $this->seedRolesAndPermissions();
    }

    #[Test]
    public function unauthenticated_stream_request_is_rejected(): void
    {
        $this->getJson('/api/admin/application-access/stream')
            ->assertUnauthorized();
    }

    #[Test]
    public function tenant_user_cannot_open_stream(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()->create([
            'name' => 'Administrator',
            'description' => 'Tenant admin',
            'level' => 10,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $this->get('/api/admin/application-access/stream', [
            'Accept' => 'text/event-stream',
        ])->assertForbidden();
    }

    #[Test]
    public function stream_capacity_returns_503(): void
    {
        config(['applicationaccess.sse.max_connections' => 1]);

        $first = $this->createPlatformUser(Role::SUPER_ADMIN);
        $second = $this->createPlatformUser(Role::SUPER_ADMIN);

        app(ApplicationAccessStreamConnectionManager::class)->acquire((int) $first->id);

        Passport::actingAs($second);

        $this->get('/api/admin/application-access/stream', [
            'Accept' => 'text/event-stream',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'stream_capacity');
    }

    #[Test]
    public function authorized_stream_returns_event_stream_headers(): void
    {
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $response = $this->call(
            'GET',
            '/api/admin/application-access/stream',
            [],
            [],
            [],
            ['HTTP_ACCEPT' => 'text/event-stream'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    private function seedRolesAndPermissions(): void
    {
        foreach ([
            [Role::SUPER_ADMIN, Role::LEVEL_SUPER_ADMIN],
            [Role::EKKLESIA_ADMIN, Role::LEVEL_EKKLESIA_ADMIN],
        ] as [$name, $level]) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'description' => $name,
                    'level' => $level,
                    'active' => 1,
                    'tenant_id' => null,
                    'role_type' => Role::ROLE_TYPE_PLATFORM,
                ]
            );
        }

        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();
    }

    private function createPlatformUser(string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }
}
