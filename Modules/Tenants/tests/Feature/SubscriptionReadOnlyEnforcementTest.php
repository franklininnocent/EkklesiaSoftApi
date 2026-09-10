<?php

namespace Modules\Tenants\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\Role;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SubscriptionReadOnlyEnforcementTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private SacramentType $baptismType;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED]);

        if (\Illuminate\Support\Facades\Schema::hasTable('subscription_settings')) {
            SubscriptionSettings::current()->update(['grace_period_days' => 7, 'expiring_warning_days' => 14]);
        }

        $this->baptismType = SacramentType::query()->first()
            ?? SacramentType::factory()->create(['code' => 'baptism', 'name' => 'Baptism']);
    }

    #[Test]
    public function expired_tenant_can_get_family_data_but_not_mutate(): void
    {
        $ctx = $this->makeExpiredParishAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'bcc_id' => $bcc->id,
            'created_by' => $ctx['user']->id,
        ]);

        $this->getJson('/api/families')->assertOk();
        $this->getJson('/api/families/'.$family->id)->assertOk();
        $this->getJson('/api/tenant/subscription-access')->assertOk()
            ->assertJsonPath('data.access_mode', SubscriptionService::ACCESS_MODE_READ_ONLY);

        $this->postJson('/api/families', [
            'family_name' => 'Blocked Family',
            'head_of_family' => 'Jane Doe',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        $this->putJson('/api/families/'.$family->id, [
            'family_name' => 'Renamed',
            'head_of_family' => $family->head_of_family,
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        $this->deleteJson('/api/families/'.$family->id)
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function expired_tenant_blocks_sacraments_bcc_users_and_church_profile_mutations(): void
    {
        $ctx = $this->makeExpiredParishAdmin();

        $this->postJson('/api/sacraments', $this->baptismPayload('Read Only Blocked'))
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        $this->postJson('/api/bccs', ['name' => 'Blocked BCC', 'status' => 'active'])
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        $this->postJson('/api/users', [
            'name' => 'New User',
            'email' => 'readonly-blocked-'.uniqid().'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        $this->putJson('/api/church-profile', ['patron_name' => 'St Peter'])
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function expired_entitled_tenant_can_get_donations_but_not_collect(): void
    {
        $ctx = $this->makeExpiredParishAdmin();

        $this->getJson('/api/tenant/donations/categories')->assertOk();

        $this->postJson('/api/tenant/donations/payments', [
            'amount' => '25.00',
            'payment_method' => 'cash',
            'payment_date' => now()->toDateString(),
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function expired_tenant_without_ministries_feature_cannot_access_ministries(): void
    {
        $ctx = $this->makeExpiredParishAdmin(['ministries_associations' => false]);

        $this->getJson('/api/tenant/ministries/categories')
            ->assertForbidden()
            ->assertJsonPath('reason', 'feature_not_entitled');
    }

    #[Test]
    public function expired_tenant_with_ministries_feature_can_get_ministries(): void
    {
        $ctx = $this->makeExpiredParishAdmin(['ministries_associations' => true]);
        $this->grantPermissions($ctx['role'], ['ministries.view', 'ministries.configure']);

        $this->getJson('/api/tenant/ministries/categories')->assertOk();

        $this->postJson('/api/tenant/ministries/categories', [
            'name' => 'Blocked Category',
            'code' => 'blocked-'.uniqid(),
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function allowlisted_person_matches_post_is_not_subscription_read_only(): void
    {
        $ctx = $this->makeExpiredParishAdmin();

        $response = $this->postJson('/api/persons/matches', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertNotSame(
            SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY,
            $response->json('code')
        );
    }

    #[Test]
    public function grace_period_tenant_can_still_write(): void
    {
        $ctx = $this->makeGraceParishAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Grace Family',
            'head_of_family' => 'Grace Head',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertCreated();
    }

    #[Test]
    public function in_term_tenant_can_write(): void
    {
        $ctx = $this->asTenantAdmin();
        $this->grantExtraParishPermissions($ctx['role']);
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Active Family',
            'head_of_family' => 'Active Head',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertCreated();
    }

    #[Test]
    public function enforcement_works_without_lifecycle_worker(): void
    {
        $ctx = $this->makeExpiredParishAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->assertSame(0, TenantSubscriptionAudit::query()->where('tenant_id', $ctx['tenant']->id)->count());

        $this->postJson('/api/families', [
            'family_name' => 'No Worker',
            'head_of_family' => 'Test',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function platform_renew_restores_write_access(): void
    {
        $ctx = $this->makeExpiredParishAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Before Renew',
            'head_of_family' => 'Blocked',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden();

        $super = $this->asSuperAdmin();
        $this->postJson('/api/tenant/'.$ctx['tenant']->id.'/subscription/renew', [
            'duration_months' => 12,
        ])->assertOk();

        $this->switchTo($ctx['user']);
        $this->postJson('/api/families', [
            'family_name' => 'After Renew',
            'head_of_family' => 'Allowed',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertCreated();
    }

    #[Test]
    public function reactivate_without_renew_stays_read_only_when_past_grace(): void
    {
        $ctx = $this->makeExpiredParishAdmin();
        $ctx['tenant']->update(['subscription_suspended_at' => now()->subDay()]);
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $super = $this->asSuperAdmin();
        $this->postJson('/api/tenant/'.$ctx['tenant']->id.'/subscription/reactivate')
            ->assertOk();

        $this->switchTo($ctx['user']);
        $this->postJson('/api/families', [
            'family_name' => 'Still Blocked',
            'head_of_family' => 'Blocked',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);
    }

    #[Test]
    public function expired_tenant_cannot_access_other_tenant_family(): void
    {
        $expired = $this->makeExpiredParishAdmin();
        $active = $this->asTenantAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $active['tenant']->id]);
        $otherFamily = Family::factory()->create([
            'tenant_id' => $active['tenant']->id,
            'bcc_id' => $bcc->id,
        ]);

        $this->switchTo($expired['user']);
        $this->getJson('/api/families/'.$otherFamily->id)->assertNotFound();
    }

    #[Test]
    public function clock_boundary_at_grace_end_blocks_writes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        $endsAt = Carbon::parse('2026-05-25 12:00:00');
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => $endsAt,
            'trial_ends_at' => null,
            'features' => ['donations', 'events', 'groups'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            array_merge($this->parishAdminPermissionNames(), $this->extraParishPermissionNames()),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        $service = app(SubscriptionService::class);
        $this->assertSame(SubscriptionService::STATUS_GRACE_PERIOD, $service->resolveStatus($tenant->fresh()));

        $bcc = BCC::factory()->create(['tenant_id' => $tenant->id]);
        $this->postJson('/api/families', [
            'family_name' => 'Grace Boundary',
            'head_of_family' => 'OK',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertCreated();

        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:01'));
        $this->assertSame(SubscriptionService::STATUS_EXPIRED, $service->resolveStatus($tenant->fresh()));

        $this->postJson('/api/families', [
            'family_name' => 'After Grace',
            'head_of_family' => 'Blocked',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_READ_ONLY);

        Carbon::setTestNow();
    }

    #[Test]
    public function lifecycle_worker_records_audit_idempotently(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
            'trial_ends_at' => null,
            'features' => ['donations'],
        ]);

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();
        $count = TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_expired')
            ->count();
        $this->assertSame(1, $count);

        $this->artisan('tenants:subscription-lifecycle')->assertSuccessful();
        $this->assertSame(1, TenantSubscriptionAudit::query()
            ->where('tenant_id', $tenant->id)
            ->where('operation', 'entered_expired')
            ->count());
    }

    /**
     * @param  array{ministries_associations?: bool}  $options
     * @return array{tenant: Tenant, user: \Modules\Authentication\Models\User, role: Role}
     */
    private function makeExpiredParishAdmin(array $options = []): array
    {
        $withMinistries = $options['ministries_associations'] ?? false;
        $features = ['donations', 'events', 'groups'];
        if ($withMinistries) {
            $features[] = 'ministries_associations';
        }

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => $features,
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            array_merge($this->parishAdminPermissionNames(), $this->extraParishPermissionNames()),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        return $ctx;
    }

    /**
     * @return array{tenant: Tenant, user: \Modules\Authentication\Models\User, role: Role}
     */
    private function makeGraceParishAdmin(): array
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(2),
            'trial_ends_at' => null,
            'features' => ['donations', 'events', 'groups'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            array_merge($this->parishAdminPermissionNames(), $this->extraParishPermissionNames()),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        return $ctx;
    }

    private function grantExtraParishPermissions(Role $role): void
    {
        $this->grantPermissions($role, $this->extraParishPermissionNames());
    }

    /**
     * @return list<string>
     */
    private function extraParishPermissionNames(): array
    {
        return [
            'families.view',
            'families.create',
            'families.edit',
            'families.delete',
            'users.view',
            'users.create',
            'ministries.view',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baptismPayload(string $recipientName): array
    {
        return [
            'sacrament_type_id' => $this->baptismType->id,
            'recipient_name' => $recipientName,
            'date_administered' => '2025-01-15',
            'place_administered' => 'St. Mary Church',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'father_name' => 'Joseph Father',
            'mother_name' => 'Mary Mother',
            'minister_name' => 'Fr. Joseph',
            'minister_title' => 'Fr.',
        ];
    }
}
