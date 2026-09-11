<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationAccessPermissionCatalog;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Illuminate\Http\Request;
use Modules\ApplicationAccess\Services\ApplicationAccessThreatWriter;
use Modules\Tenants\Support\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessSecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['applicationaccess.telemetry_enabled' => true]);

        app()->instance(TenantContext::class, TenantContext::empty());
        User::flushRequestPermissionCache();
        $this->seedRolesAndPermissions();
    }

    #[Test]
    public function revoke_session_marks_oauth_token_revoked_and_ends_session(): void
    {
        $fixture = $this->seedSessionWithToken();
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);

        Passport::actingAs($admin);

        $this->postJson('/api/admin/application-access/sessions/'.$fixture['session']->id.'/revoke')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('revoked_self', false);

        $this->assertTrue((bool) DB::table('oauth_access_tokens')
            ->where('id', $fixture['tokenId'])
            ->value('revoked'));

        $session = ApplicationAccessSession::query()->findOrFail($fixture['session']->id);
        $this->assertSame(ApplicationAccessEnums::SESSION_REVOKED, $session->status);
        $this->assertSame('revoked', $session->end_reason);

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'PRIVILEGED_OPERATION',
            'reason_code' => 'revoke_session',
            'resource_id' => $fixture['session']->id,
        ]);
    }

    #[Test]
    public function blocked_public_ip_is_denied_on_api_but_up_is_allowed(): void
    {
        $blockedIp = '203.0.113.77';
        ApplicationIpBlockRule::query()->create([
            'ip_address' => $blockedIp,
            'scope' => 'API',
            'reason' => 'Test block',
            'created_by' => null,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => $blockedIp])
            ->postJson('/api/auth/login', [
                'email' => 'blocked@example.com',
                'password' => 'password',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ip_blocked');

        $this->withServerVariables(['REMOTE_ADDR' => $blockedIp])
            ->get('/up')
            ->assertOk();
    }

    #[Test]
    public function cannot_block_own_ip_or_entire_internet(): void
    {
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/admin/application-access/ip-blocks', [
                'ip_address' => '203.0.113.10',
                'reason' => 'Self block attempt',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ip_address']);

        $this->postJson('/api/admin/application-access/ip-blocks', [
            'ip_address' => '203.0.113.10',
            'cidr' => '0.0.0.0/0',
            'reason' => 'Wildcard block attempt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cidr']);
    }

    #[Test]
    public function ip_block_can_be_revoked(): void
    {
        $admin = $this->createPlatformUser(Role::SUPER_ADMIN);
        Passport::actingAs($admin);

        $create = $this->postJson('/api/admin/application-access/ip-blocks', [
            'ip_address' => '203.0.113.88',
            'reason' => 'Abuse probe',
        ])->assertCreated();

        $ruleId = $create->json('data.id');

        $this->postJson('/api/admin/application-access/ip-blocks/'.$ruleId.'/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked_at', fn ($value) => $value !== null);

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'PRIVILEGED_OPERATION',
            'reason_code' => 'unblock_ip',
            'resource_id' => $ruleId,
        ]);
    }

    #[Test]
    public function invalid_token_signal_aggregates_in_minute_window(): void
    {
        $service = app(\Modules\ApplicationAccess\Services\ApplicationSecuritySignalService::class);

        $service->recordInvalidToken('203.0.113.55');
        $service->recordInvalidToken('203.0.113.55');

        $this->assertSame(
            1,
            ApplicationSecuritySignal::query()
                ->where('signal_type', 'INVALID_TOKEN')
                ->where('source_ip', '203.0.113.55')
                ->count()
        );

        $signal = ApplicationSecuritySignal::query()
            ->where('signal_type', 'INVALID_TOKEN')
            ->first();

        $this->assertGreaterThanOrEqual(2, $signal?->event_count);
    }

    #[Test]
    public function support_session_violation_writer_records_security_event(): void
    {
        $user = User::factory()->create(['active' => 1]);
        $request = Request::create('/api/families', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->setUserResolver(fn () => $user);

        app(ApplicationAccessThreatWriter::class)->recordSupportSessionViolation($request, 'readonly_mutation');

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'SUPPORT_SESSION_VIOLATION',
            'reason_code' => 'readonly_mutation',
        ]);

        $this->assertDatabaseHas('application_security_signals', [
            'signal_type' => 'SUPPORT_SESSION_VIOLATION',
            'source_ip' => '203.0.113.10',
        ]);
    }

    #[Test]
    public function login_failure_always_creates_individual_security_event(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $this->assertSame(1, ApplicationSecurityEvent::query()->where('event_type', 'LOGIN_FAILURE')->count());
    }

    private function seedSessionWithToken(): array
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

        $tokenId = (string) Str::uuid();
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

        $clientId = DB::table('oauth_clients')->value('id');

        DB::table('oauth_access_tokens')->insert([
            'id' => $tokenId,
            'user_id' => $user->id,
            'client_id' => $clientId,
            'name' => 'API Token',
            'scopes' => json_encode([]),
            'revoked' => false,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = app(ApplicationAccessSessionRepository::class)->create([
            'oauth_access_token_id' => $tokenId,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'identity_type' => ApplicationAccessEnums::IDENTITY_TENANT_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_TENANT,
            'authentication_status' => 'authenticated',
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => now()->subHour(),
            'last_activity_at' => now(),
        ]);

        return ['session' => $session, 'tokenId' => $tokenId, 'user' => $user];
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
