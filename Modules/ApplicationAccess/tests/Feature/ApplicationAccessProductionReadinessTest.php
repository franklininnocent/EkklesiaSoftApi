<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Services\ApplicationAccessCaptureService;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\SupportAccess\Models\SupportSession;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['applicationaccess.telemetry_enabled' => true]);

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();
        Cache::flush();

        $this->seedRolesAndPermissions();
        $this->seedPasswordGrantClient();

        Route::middleware('api')->post('/api/_test/application-access/capture-mutation', fn () => response()->json(['ok' => true]))
            ->name('test.capture.store');
    }

    #[Test]
    public function capture_ignores_forged_tenant_id_in_request_body(): void
    {
        $tenantA = Tenant::factory()->create([
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'active' => 1,
        ]);
        $tenantB = Tenant::factory()->create([
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'active' => 1,
        ]);
        $user = $this->createTenantUserFor($tenantA, 'password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/_test/application-access/capture-mutation', [
                'tenant_id' => $tenantB->id,
                'name' => 'Forged parish payload',
            ])
            ->assertOk();

        $event = ApplicationAccessEvent::query()->latest('occurred_at')->first();
        $this->assertNotNull($event);
        $this->assertSame($tenantA->id, (int) $event->tenant_id);
        $this->assertNotSame($tenantB->id, (int) $event->tenant_id);
    }

    #[Test]
    public function capture_uses_remote_addr_when_forwarded_ip_is_untrusted(): void
    {
        putenv('TRUSTED_PROXIES');
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->withServerVariables([
                'REMOTE_ADDR' => '10.0.0.9',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.55',
                'HTTP_CF_CONNECTING_IP' => '203.0.113.99',
            ])
            ->postJson('/api/_test/application-access/capture-mutation', ['tenant_id' => 99999])
            ->assertOk();

        $event = ApplicationAccessEvent::query()
            ->where('normalized_route', '/api/_test/application-access/capture-mutation')
            ->latest('occurred_at')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('10.0.0.9', $event->ip_address);
    }

    #[Test]
    public function capture_derives_support_session_from_context_not_request_body(): void
    {
        $tenant = Tenant::factory()->create([
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'active' => 1,
        ]);
        $user = $this->createTenantUserFor($tenant, 'password');
        $supportSessionId = (string) Str::uuid();

        SupportSession::query()->create([
            'id' => $supportSessionId,
            'support_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'mode' => 'standard',
            'reason_code' => 'diagnosis',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $supportSession = new ActiveSupportSession(
            id: $supportSessionId,
            tenantId: $tenant->id,
            mode: SupportSessionMode::Standard,
            supportUserId: $user->id,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        app()->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $supportSession)
        );

        $request = \Illuminate\Http\Request::create(
            '/api/_test/application-access/capture-mutation',
            'POST',
            ['support_session_id' => (string) Str::uuid()],
            server: ['REMOTE_ADDR' => '203.0.113.10']
        );
        $request->setUserResolver(fn () => $user);

        app(ApplicationAccessCaptureService::class)->capture(
            $request,
            response()->json(['ok' => true], 200)
        );

        $event = ApplicationAccessEvent::query()
            ->where('normalized_route', '/api/_test/application-access/capture-mutation')
            ->latest('occurred_at')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame($supportSessionId, $event->support_session_id);
    }

    #[Test]
    public function view_only_platform_user_cannot_run_privileged_mutations_or_stream(): void
    {
        $fixture = $this->seedSessionFixture();
        $viewer = $this->createViewOnlyPlatformUser();

        Passport::actingAs($viewer);

        $this->postJson('/api/admin/application-access/sessions/'.$fixture['session']->id.'/revoke')
            ->assertForbidden();

        $this->postJson('/api/admin/application-access/ip-blocks', [
            'ip_address' => '203.0.113.88',
            'reason' => 'Abuse probe',
        ])->assertForbidden();

        $this->get('/api/admin/application-access/export/sessions')
            ->assertForbidden();

        $this->get('/api/admin/application-access/stream', [
            'Accept' => 'text/event-stream',
        ])->assertOk();
    }

    #[Test]
    public function captured_access_events_do_not_store_request_body_secrets(): void
    {
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withToken($login->json('access_token'))
            ->postJson('/api/_test/application-access/capture-mutation', [
                'password' => 'super-secret-password',
                'refresh_token' => 'leaked-refresh-token',
                'otp' => '123456',
                'note' => 'marker-for-capture-test',
            ])
            ->assertOk();

        $serialized = json_encode(ApplicationAccessEvent::query()->get()->toArray());
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('super-secret-password', $serialized);
        $this->assertStringNotContainsString('leaked-refresh-token', $serialized);
        $this->assertStringNotContainsString('123456', $serialized);
    }

    #[Test]
    public function login_failure_security_event_does_not_store_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'must-not-persist-42',
        ])->assertUnprocessable();

        $serialized = json_encode(ApplicationSecurityEvent::query()->get()->toArray());
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('must-not-persist-42', $serialized);
    }

    /**
     * @return array{subject: User, session: ApplicationAccessSession}
     */
    private function seedSessionFixture(): array
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

        $subject = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        $session = app(ApplicationAccessSessionRepository::class)->create([
            'oauth_access_token_id' => (string) Str::uuid(),
            'user_id' => $subject->id,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'identity_type' => ApplicationAccessEnums::IDENTITY_TENANT_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_TENANT,
            'authentication_status' => 'authenticated',
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => now()->subHour(),
            'last_activity_at' => now(),
            'ip_address' => '203.0.113.44',
        ]);

        return ['subject' => $subject, 'session' => $session];
    }

    private function seedPasswordGrantClient(): void
    {
        if (DB::table('oauth_clients')->exists()) {
            return;
        }

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

    private function createViewOnlyPlatformUser(): User
    {
        $role = Role::query()->updateOrCreate(
            ['name' => Role::EKKLESIA_USER],
            [
                'description' => Role::EKKLESIA_USER,
                'level' => Role::LEVEL_EKKLESIA_USER,
                'active' => 1,
                'tenant_id' => null,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $permission = Permission::query()->where('name', 'application_access.view')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        return User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);
    }

    private function createTenantUser(string $password): User
    {
        $tenant = Tenant::factory()->create([
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->addYear(),
            'active' => 1,
        ]);

        return $this->createTenantUserFor($tenant, $password);
    }

    private function createTenantUserFor(Tenant $tenant, string $password): User
    {
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
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make($password),
        ]);
    }
}
