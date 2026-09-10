<?php

namespace Modules\Tenants\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class TenantDetailsApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    #[Test]
    public function platform_admin_can_read_tenant_details_snapshot(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();

        $response = $this->getJson('/api/tenant/'.$tenant->id.'/details');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'identity' => ['id', 'name', 'slug'],
                    'operational' => ['active'],
                    'subscription' => ['status', 'access_mode'],
                    'modules',
                    'contact',
                    'administration',
                    'church',
                    'kpis',
                    'usage' => ['storage', 'users'],
                    'users_preview',
                    'history_preview',
                    'warnings',
                    'meta' => ['tenant_id'],
                ],
            ])
            ->assertJsonPath('data.identity.id', $tenant->id);
    }

    #[Test]
    public function parish_user_cannot_read_platform_tenant_details(): void
    {
        $ctx = $this->asTenantAdmin();
        $foreign = $this->makeOperationalTenant();

        $this->getJson('/api/tenant/'.$foreign->id.'/details')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function tenant_id_tampering_cannot_expose_another_tenant(): void
    {
        $this->asSuperAdmin();
        $tenantA = Tenant::factory()->active()->create(['name' => 'Parish Alpha']);
        $tenantB = Tenant::factory()->active()->create(['name' => 'Parish Beta']);

        $this->getJson('/api/tenant/'.$tenantA->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'Parish Alpha')
            ->assertJsonPath('data.identity.id', $tenantA->id);

        $this->getJson('/api/tenant/'.$tenantB->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'Parish Beta')
            ->assertJsonPath('data.identity.id', $tenantB->id);
    }

    #[Test]
    public function user_preview_is_tenant_scoped(): void
    {
        $this->asSuperAdmin();
        $tenantA = $this->makeOperationalTenant();
        $tenantB = $this->makeOperationalTenant();

        $userA = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Alpha User',
            'email' => 'alpha@example.test',
        ]);
        User::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Beta User',
            'email' => 'beta@example.test',
        ]);

        $response = $this->getJson('/api/tenant/'.$tenantA->id.'/details')->assertOk();
        $preview = collect($response->json('data.users_preview'));

        $this->assertTrue($preview->contains('email', 'alpha@example.test'));
        $this->assertFalse($preview->contains('email', 'beta@example.test'));
    }

    #[Test]
    public function expired_tenant_returns_expired_and_read_only(): void
    {
        $this->asSuperAdmin();

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        $tenant = Tenant::factory()->active()->create([
            'plan' => 'basic',
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(30),
            'subscription_suspended_at' => null,
            'features' => ['donations', 'events'],
        ]);

        $response = $this->getJson('/api/tenant/'.$tenant->id.'/details')->assertOk();

        $response->assertJsonPath('data.subscription.status', SubscriptionService::STATUS_EXPIRED)
            ->assertJsonPath('data.subscription.access_mode', SubscriptionService::ACCESS_MODE_READ_ONLY);
    }

    #[Test]
    public function entitled_modules_remain_visible_for_expired_tenant(): void
    {
        $this->asSuperAdmin();

        $tenant = Tenant::factory()->active()->create([
            'plan' => 'basic',
            'trial_ends_at' => null,
            'subscription_ends_at' => Carbon::now()->subDays(30),
            'subscription_suspended_at' => null,
            'features' => ['donations', 'events'],
        ]);

        $response = $this->getJson('/api/tenant/'.$tenant->id.'/details')->assertOk();
        $donations = collect($response->json('data.modules'))->firstWhere('key', 'donations');

        $this->assertNotNull($donations);
        $this->assertTrue($donations['entitled']);
        $this->assertSame(SubscriptionService::ACCESS_MODE_READ_ONLY, $donations['access_mode']);
    }

    #[Test]
    public function storage_calculation_is_scoped_to_tenant_prefix(): void
    {
        Storage::fake('public');
        $this->asSuperAdmin();

        $tenantA = $this->makeOperationalTenant();
        $tenantB = $this->makeOperationalTenant();

        Storage::disk('public')->put('tenants/'.$tenantA->id.'/logos/logo.txt', str_repeat('a', 1048576));
        Storage::disk('public')->put('tenants/'.$tenantB->id.'/logos/logo.txt', str_repeat('b', 2097152));

        $responseA = $this->getJson('/api/tenant/'.$tenantA->id.'/details')->assertOk();
        $responseB = $this->getJson('/api/tenant/'.$tenantB->id.'/details')->assertOk();

        $this->assertEquals(1.0, (float) $responseA->json('data.usage.storage.used_mb'));
        $this->assertEquals(2.0, (float) $responseB->json('data.usage.storage.used_mb'));
    }

    #[Test]
    public function response_does_not_leak_passwords_or_tokens(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        DB::table('oauth_access_tokens')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'name' => 'API Token',
            'scopes' => '[]',
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $json = json_encode($this->getJson('/api/tenant/'.$tenant->id.'/details')->json());

        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('remember_token', strtolower($json));
        $this->assertStringNotContainsString('oauth_access_tokens', strtolower($json));
    }

    #[Test]
    public function church_snapshot_is_tenant_scoped(): void
    {
        $this->asSuperAdmin();
        $tenantA = $this->makeOperationalTenant();
        $tenantB = $this->makeOperationalTenant();

        ChurchProfile::query()->create([
            'tenant_id' => $tenantA->id,
            'patron_name' => 'Saint Alpha',
        ]);
        ChurchProfile::query()->create([
            'tenant_id' => $tenantB->id,
            'patron_name' => 'Saint Beta',
        ]);

        $this->getJson('/api/tenant/'.$tenantA->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.church.patron_name', 'Saint Alpha');

        $this->getJson('/api/tenant/'.$tenantB->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.church.patron_name', 'Saint Beta');
    }

    #[Test]
    public function current_bishop_is_resolved_from_leadership_query(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();

        $archdioceseId = DB::table('archdioceses')->insertGetId([
            'name' => 'Diocese of Snapshot',
            'code' => 'SNAPSHOT_'.uniqid('', true),
            'country' => 'India',
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentBishopId = DB::table('bishops')->insertGetId([
            'full_name' => 'Most Rev. Current Bishop',
            'archdiocese_id' => $archdioceseId,
            'appointed_date' => '2025-01-01',
            'status' => 'active',
            'is_current' => true,
            'precedence_order' => 1,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $formerBishopId = DB::table('bishops')->insertGetId([
            'full_name' => 'Most Rev. Former Bishop',
            'archdiocese_id' => $archdioceseId,
            'appointed_date' => '2020-01-01',
            'status' => 'retired',
            'is_current' => false,
            'precedence_order' => 2,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bishop_appointments')->insert([
            'id' => (string) Str::uuid(),
            'bishop_id' => $currentBishopId,
            'diocese_id' => $archdioceseId,
            'appointed_date' => '2025-01-01',
            'effective_date' => '2025-01-01',
            'canonical_role' => 'diocesan_bishop',
            'appointment_status' => 'current',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ChurchProfile::query()->create([
            'tenant_id' => $tenant->id,
            'archdiocese_id' => $archdioceseId,
            'bishop_id' => $formerBishopId,
        ]);

        $this->getJson('/api/tenant/'.$tenant->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.church.bishop.id', $currentBishopId)
            ->assertJsonPath('data.church.bishop.name', 'Most Rev. Current Bishop');
    }

    #[Test]
    public function legacy_show_endpoint_remains_compatible(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();

        $this->getJson('/api/tenant/'.$tenant->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $tenant->id)
            ->assertJsonStructure(['stats' => ['total_users', 'subscription_status']]);
    }

    #[Test]
    public function family_kpis_are_included_when_available(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();
        $family = Family::factory()->active()->create(['tenant_id' => $tenant->id]);
        FamilyMember::factory()->create(['family_id' => $family->id, 'status' => 'active']);

        $response = $this->getJson('/api/tenant/'.$tenant->id.'/details')->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('data.kpis.families'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.kpis.members'));
    }

    #[Test]
    public function primary_pastor_is_included_in_church_snapshot(): void
    {
        $this->asSuperAdmin();
        $tenant = $this->makeOperationalTenant();

        ChurchLeadership::query()->create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Fr. John Pastor',
            'role' => 'Pastor',
            'is_primary' => 1,
            'active' => 1,
            'appointed_date' => now()->subYear(),
        ]);

        $this->getJson('/api/tenant/'.$tenant->id.'/details')
            ->assertOk()
            ->assertJsonPath('data.church.primary_pastor.name', 'Fr. John Pastor');
    }
}
