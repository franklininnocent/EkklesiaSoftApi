<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Authentication\Models\Role;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class SubscriptionViewOnlyLockedEnforcementTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED,
            'tenants.subscription.view_only_locked_enforcement' => true,
        ]);

        SubscriptionSettings::current()->update([
            'grace_period_days' => 0,
            'view_only_allowance_days' => 30,
        ]);
    }

    #[Test]
    public function view_only_blocks_family_mutation_with_subscription_view_only_code(): void
    {
        $ctx = $this->makeViewOnlyParishAdmin();
        $bcc = BCC::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->getJson('/api/families')->assertOk();

        $this->postJson('/api/families', [
            'family_name' => 'Blocked',
            'head_of_family' => 'Jane',
            'bcc_id' => $bcc->id,
            'status' => 'active',
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_VIEW_ONLY);
    }

    #[Test]
    public function locked_denies_parish_family_list_get(): void
    {
        $ctx = $this->makeLockedParishAdmin();
        Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->getJson('/api/families')
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_LOCKED);
    }

    #[Test]
    public function locked_allows_subscription_access_snapshot(): void
    {
        $ctx = $this->makeLockedParishAdmin();

        $this->getJson('/api/tenant/subscription-access')
            ->assertOk()
            ->assertJsonPath('data.access_mode', SubscriptionService::ACCESS_MODE_LOCKED);
    }

    #[Test]
    public function view_only_allows_person_matches_post(): void
    {
        $ctx = $this->makeViewOnlyParishAdmin();

        $response = $this->postJson('/api/persons/matches', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertNotSame(SubscriptionService::CODE_SUBSCRIPTION_VIEW_ONLY, $response->json('code'));
        $this->assertNotSame(SubscriptionService::CODE_SUBSCRIPTION_LOCKED, $response->json('code'));
    }

    #[Test]
    public function locked_allows_parish_support_ticket_list(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(5),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);
        SubscriptionSettings::current()->update(['view_only_allowance_days' => 0]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            ['support.tickets.view'],
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        $this->getJson('/api/tenant/support/tickets')
            ->assertOk();
    }

    #[Test]
    public function locked_retains_family_data(): void
    {
        $ctx = $this->makeLockedParishAdmin();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->getJson('/api/families')
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_LOCKED);

        $this->assertDatabaseHas('families', ['id' => $family->id]);
    }

    #[Test]
    public function view_only_blocks_donation_report_export_download(): void
    {
        $ctx = $this->makeViewOnlyParishAdmin();

        $this->getJson('/api/tenant/donations/reports/exports/test-export-id/download')
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_VIEW_ONLY);
    }

    #[Test]
    public function locked_entitlement_gate_returns_subscription_locked_code(): void
    {
        SubscriptionSettings::current()->update(['view_only_allowance_days' => 0]);

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(5),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            ['donations.reports'],
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        $this->getJson('/api/tenant/donations/reports/catalog')
            ->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_LOCKED);
    }

    #[Test]
    public function view_only_blocks_donation_report_export_queue(): void
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(5),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            ['donations.reports'],
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        $this->postJson('/api/tenant/donations/reports/export', [
            'report' => 'payments',
            'format' => 'csv',
            'date_from' => now()->subMonth()->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertForbidden()
            ->assertJsonPath('code', SubscriptionService::CODE_SUBSCRIPTION_VIEW_ONLY);
    }

    /**
     * @return array{tenant: Tenant, user: \Modules\Authentication\Models\User, role: Role}
     */
    private function makeViewOnlyParishAdmin(): array
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(5),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            ['families.view', 'families.create'],
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
    private function makeLockedParishAdmin(): array
    {
        SubscriptionSettings::current()->update(['view_only_allowance_days' => 0]);

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(5),
            'trial_ends_at' => null,
            'subscription_suspended_at' => null,
            'features' => ['donations'],
        ]);

        $ctx = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            ['families.view', 'families.create'],
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->switchTo($ctx['user']);

        return $ctx;
    }
}
