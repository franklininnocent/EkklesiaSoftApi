<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Services\ApplicationAccessCaptureService;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaptureApplicationAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['applicationaccess.telemetry_enabled' => true]);

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();

        $this->seedPasswordGrantClient();

        Route::middleware('api')->get('/api/_test/application-access/capture', fn () => response()->json(['ok' => true]))
            ->name('test.capture.view');
    }

    #[Test]
    public function authenticated_get_creates_throttled_view_event(): void
    {
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/_test/application-access/capture')
            ->assertOk();

        $this->withToken($login->json('access_token'))
            ->getJson('/api/_test/application-access/capture')
            ->assertOk();

        $this->assertSame(1, ApplicationAccessEvent::query()->where('event_type', 'VIEW')->count());
    }

    #[Test]
    public function exempt_health_path_creates_no_rows(): void
    {
        $user = $this->createPlatformUser();

        $this->actingAs($user, 'api')
            ->getJson('/api/admin/application-access/health')
            ->assertOk();

        $this->assertSame(0, ApplicationAccessEvent::query()->count());
    }

    #[Test]
    public function capture_failure_does_not_change_http_status(): void
    {
        $this->mock(ApplicationAccessCaptureService::class, function ($mock): void {
            $mock->shouldReceive('capture')->andThrow(new \RuntimeException('capture failed'));
        });

        $this->getJson('/api/_test/application-access/capture')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    private function seedPasswordGrantClient(): void
    {
        DB::table('oauth_clients')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'Test Password Client',
            'secret' => null,
            'provider' => 'users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTenantUser(string $password): User
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

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make($password),
        ]);
    }

    private function createPlatformUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['name' => Role::SUPER_ADMIN],
            [
                'description' => Role::SUPER_ADMIN,
                'level' => Role::LEVEL_SUPER_ADMIN,
                'active' => 1,
                'tenant_id' => null,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        ApplicationAccessPermissionCatalog::syncPermissionsAndRoles();

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }
}
