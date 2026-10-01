<?php

namespace Modules\Tenants\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationSetting;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantExecutiveDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function executive_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/tenant/dashboard/executive')
            ->assertUnauthorized();
    }

    #[Test]
    public function executive_dashboard_returns_sectioned_payload_for_tenant_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->grantUserPermissions($user, ['families.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'snapshot' => ['state'],
                    'attention' => ['state'],
                    'stewardship' => ['state'],
                    'worship' => ['state'],
                    'celebrations' => ['state'],
                    'quick_actions' => ['state'],
                ],
            ]);
    }

    #[Test]
    public function stewardship_is_forbidden_without_donations_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['donations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive');

        $response->assertOk()
            ->assertJsonPath('data.stewardship.state', 'forbidden')
            ->assertJsonMissingPath('data.stewardship.data');
    }

    #[Test]
    public function snapshot_omits_financial_cards_without_donations_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['donations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive');

        $response->assertOk();
        $cards = $response->json('data.snapshot.data.cards') ?? [];
        $this->assertArrayNotHasKey('collections', $cards);
        $this->assertArrayNotHasKey('overdue', $cards);
    }

    #[Test]
    public function stewardship_snapshot_matches_donation_dashboard_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'UTC'));

        $tenant = Tenant::factory()->active()->create([
            'features' => ['donations'],
            'settings' => ['timezone' => 'UTC', 'language' => 'en', 'currency' => 'INR'],
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view', 'donations.view']);

        DonationSetting::create([
            'tenant_id' => $tenant->id,
            'financial_year_start_month' => '01',
            'financial_year_start_day' => '01',
            'default_currency' => 'INR',
        ]);

        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        DonationPayment::create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'payment_number' => 'PAY-EXEC-RECON',
            'payer_name' => 'Recon Payer',
            'payment_date' => Carbon::now()->toDateString(),
            'amount' => 94170,
            'currency' => 'INR',
            'method' => 'cash',
            'status' => 'succeeded',
            'source_type' => 'general',
        ]);

        Passport::actingAs($user);

        $donations = $this->getJson('/api/tenant/donations/dashboard/summary')->assertOk();
        $executive = $this->getJson('/api/tenant/dashboard/executive')->assertOk();

        $stew = $executive->json('data.stewardship.data');
        $snapshot = $donations->json('data.snapshot');
        $this->assertIsArray($stew);
        $this->assertIsArray($snapshot);
        $this->assertArrayNotHasKey('collection_trend', $stew);
        $this->assertArrayNotHasKey('collections', $executive->json('data.snapshot.data.cards') ?? []);
        $this->assertArrayNotHasKey('overdue', $executive->json('data.snapshot.data.cards') ?? []);
        $this->assertSame($snapshot['month']['collected'], $stew['collected']);
        $this->assertSame($snapshot['month']['comparison_collected'], $stew['comparison_collected']);
        $this->assertSame($snapshot['month']['comparison_start'], $stew['comparison_start']);
        $this->assertSame($snapshot['month']['comparison_end'], $stew['comparison_end']);
        $this->assertSame($snapshot['month']['growth_pct'], $stew['growth_pct']);
        $this->assertSame($snapshot['outstanding_contributions'], $stew['outstanding_contributions']);
        $this->assertSame($snapshot['project_installments']['open'], $stew['project_installments_open']);
        $this->assertSame($snapshot['overdue_amount'], $stew['overdue_amount']);
        $this->assertSame($snapshot['due_next_14_days_amount'], $stew['due_next_14_days_amount']);
        $this->assertSame($snapshot['due_later_amount'], $stew['due_later_amount']);
        $this->assertIsArray($stew['due_schedule']);
        $this->assertSame($snapshot['overdue_families'], $stew['overdue_families']);
        $this->assertSame($snapshot['participation']['rate'], $stew['participation_rate']);
        $this->assertSame($snapshot['participation']['participating'], $stew['participation_participating']);
        $this->assertSame($snapshot['participation']['active'], $stew['participation_active']);
        $this->assertSame($snapshot['participation']['net_change_vs_prior_window'], $stew['participation_net_change']);
        $this->assertSame($snapshot['as_of'], $stew['as_of']);
        $this->assertSame(
            (string) $donations->json('data.financial_health.label'),
            (string) $stew['giving_health_label']
        );
        $this->assertSame(
            (string) $donations->json('data.tenant_context.currency_code'),
            (string) $stew['currency_code']
        );

        Carbon::setTestNow();
    }

    #[Test]
    public function worship_is_forbidden_when_mass_intentions_feature_is_off(): void
    {
        $tenant = Tenant::factory()->create(['features' => []]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view', 'mass.intentions.view']);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive');

        $response->assertOk()
            ->assertJsonPath('data.worship.state', 'forbidden');
    }

    /**
     * @param  list<string>  $names
     */
    private function grantUserPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'Test',
                    'category' => 'test',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }
        $user->permissions()->syncWithoutDetaching($ids);
    }
}
