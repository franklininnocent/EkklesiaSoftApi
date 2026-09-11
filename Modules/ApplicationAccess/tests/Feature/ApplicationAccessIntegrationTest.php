<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Services\ApplicationAccessThreatWriter;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Http\Middleware\EnsureSubscriptionAccessMode;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SubscriptionRouteAllowlist;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessIntegrationTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        User::flushRequestPermissionCache();

        parent::tearDown();
    }

    #[Test]
    public function cross_tenant_attempt_records_actor_tenant_and_source_ip(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        $request = Request::create('/api/families/999', 'GET', server: ['REMOTE_ADDR' => '203.0.113.66']);
        $request->setUserResolver(fn () => $actor);

        app(ApplicationAccessThreatWriter::class)->recordCrossTenantAttempt($request, $actor);

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'CROSS_TENANT_ATTEMPT',
            'tenant_id' => $tenant->id,
            'source_ip' => '203.0.113.66',
            'authorization_result' => 'denied',
            'reason_code' => 'cross_tenant',
        ]);
    }

    #[Test]
    public function ekklesia_user_without_application_access_permission_is_denied(): void
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
        $role->permissions()->sync([]);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
            'password' => Hash::make('password'),
        ]);

        Passport::actingAs($user);

        $this->getJson('/api/admin/application-access/dashboard')->assertForbidden();
    }

    #[Test]
    public function tenant_filter_only_returns_matching_parish_sessions(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $repo = app(ApplicationAccessSessionRepository::class);

        foreach ([$tenantA, $tenantB] as $tenant) {
            $user = User::factory()->create([
                'tenant_id' => $tenant->id,
                'active' => 1,
            ]);

            $repo->create([
                'oauth_access_token_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'identity_type' => ApplicationAccessEnums::IDENTITY_TENANT_USER,
                'access_context' => ApplicationAccessEnums::CONTEXT_TENANT,
                'authentication_status' => 'authenticated',
                'status' => ApplicationAccessEnums::SESSION_ACTIVE,
                'started_at' => now()->subHour(),
                'last_activity_at' => now(),
            ]);
        }

        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $this->getJson('/api/admin/application-access/sessions?tenant_id='.$tenantA->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant_id', $tenantA->id);

        $this->getJson('/api/admin/application-access/sessions?tenant_id='.$tenantB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.tenant_id', $tenantB->id);
    }

    #[Test]
    public function revoked_session_token_is_rejected_on_protected_api(): void
    {
        $user = $this->createTenantUser('password');

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = (string) $login->json('access_token');
        $session = ApplicationAccessSession::query()->where('user_id', $user->id)->firstOrFail();

        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $this->postJson('/api/admin/application-access/sessions/'.$session->id.'/revoke')
            ->assertOk();

        auth()->guard('api')->forgetUser();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertUnauthorized();
    }

    #[Test]
    public function timeline_orders_by_occurred_at_desc_then_id(): void
    {
        $fixture = $this->seedSessionFixture();
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        $occurredAt = Carbon::parse('2026-01-15 12:00:00');

        $ids = [];
        foreach (['aaa', 'bbb', 'ccc'] as $suffix) {
            $ids[] = $id = (string) Str::uuid();
            ApplicationAccessEvent::query()->create([
                'id' => $id,
                'access_session_id' => $fixture['session']->id,
                'user_id' => $fixture['subject']->id,
                'event_type' => 'VIEW',
                'action' => 'VIEW',
                'occurred_at' => $occurredAt,
                'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
            ]);
        }

        Passport::actingAs($admin);

        $response = $this->getJson(
            '/api/admin/application-access/sessions/'.$fixture['session']->id.'/timeline?per_page=10'
        )->assertOk();

        $returnedIds = array_column($response->json('data'), 'id');
        rsort($ids);
        $this->assertSame($ids, $returnedIds);
    }

    #[Test]
    public function trusted_proxy_honors_forwarded_ip_when_cidr_is_configured(): void
    {
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $request = Request::create(
            '/api/admin/application-access/health',
            'GET',
            server: [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
            ]
        );

        $this->assertSame('203.0.113.5', $request->ip());

        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    #[Test]
    public function subscription_allowlist_permits_application_access_revoke_when_parish_expired(): void
    {
        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        $allowlist = SubscriptionRouteAllowlist::fromConfig();
        $revoke = Request::create('/api/admin/application-access/sessions/abc/revoke', 'POST');
        $blocked = Request::create('/api/families', 'POST');

        $this->assertTrue($allowlist->allows($revoke));
        $this->assertFalse($allowlist->allows($blocked));
    }

    #[Test]
    public function expired_parish_support_session_allows_application_access_revoke_middleware(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            $this->markTestSkipped('subscription_settings table not migrated');
        }

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);
        \Modules\Tenants\Models\SubscriptionSettings::current()->update(['grace_period_days' => 0]);

        $tenant = Tenant::factory()->create([
            'trial_ends_at' => null,
            'subscription_ends_at' => now()->subDays(10),
            'subscription_suspended_at' => null,
            'active' => 1,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
        ]);

        $session = new ActiveSupportSession(
            id: (string) Str::uuid(),
            tenantId: $tenant->id,
            mode: SupportSessionMode::Readonly,
            supportUserId: $user->id,
            expiresAt: new \DateTimeImmutable('+30 minutes'),
            reasonCode: 'diagnosis',
        );

        $this->app->instance(
            TenantContext::class,
            TenantContext::fromUserAndSession($user, $session)
        );

        $middleware = app(EnsureSubscriptionAccessMode::class);

        $familyRequest = Request::create('/api/families', 'POST');
        $familyRequest->setUserResolver(fn () => $user);
        $familyPost = $middleware->handle(
            $familyRequest,
            static fn () => response('ok', 200)
        );
        $this->assertSame(403, $familyPost->getStatusCode());

        $revokeRequest = Request::create('/api/admin/application-access/sessions/abc/revoke', 'POST');
        $revokeRequest->setUserResolver(fn () => $user);
        $revokePost = $middleware->handle(
            $revokeRequest,
            static fn () => response('ok', 200)
        );
        $this->assertSame(200, $revokePost->getStatusCode());
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
            'last_activity_at' => now()->subMinutes(5),
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
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make($password),
        ]);
    }
}
