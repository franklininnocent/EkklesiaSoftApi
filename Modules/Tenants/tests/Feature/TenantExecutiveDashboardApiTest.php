<?php

namespace Modules\Tenants\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\BCC\Services\BccDashboardService;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationSetting;
use Modules\Family\app\Services\MemberCelebrationsService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
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

    public function executive_dashboard_snapshot_bundle_omits_operational_sections(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive?bundle=snapshot');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'snapshot' => ['state'],
                ],
            ])
            ->assertJsonMissingPath('data.stewardship')
            ->assertJsonMissingPath('data.celebrations')
            ->assertJsonMissingPath('data.attention')
            ->assertJsonMissingPath('data.ministries');
    }

    #[Test]
    public function executive_dashboard_mass_bundle_returns_intentions_and_worship_only(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive?bundle=mass');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'mass_intentions' => ['state'],
                    'worship' => ['state'],
                ],
            ])
            ->assertJsonMissingPath('data.snapshot')
            ->assertJsonMissingPath('data.stewardship');
    }

    #[Test]
    public function executive_dashboard_stewardship_bundle_does_not_include_attention(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['donations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view', 'donations.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive?bundle=stewardship');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stewardship' => ['state'],
                ],
            ])
            ->assertJsonMissingPath('data.attention')
            ->assertJsonMissingPath('data.snapshot');
    }

    #[Test]
    public function executive_dashboard_primary_bundle_returns_snapshot_sections_only(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive?bundle=primary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'snapshot' => ['state'],
                    'celebrations' => ['state'],
                    'quick_actions' => ['state'],
                ],
            ])
            ->assertJsonMissingPath('data.stewardship')
            ->assertJsonMissingPath('data.attention')
            ->assertJsonMissingPath('data.ministries');
    }

    #[Test]
    public function executive_dashboard_secondary_bundle_returns_operational_sections_only(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['donations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view', 'donations.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive?bundle=secondary');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'attention' => ['state'],
                    'stewardship' => ['state'],
                ],
            ])
            ->assertJsonMissingPath('data.snapshot');
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
                    'ministries' => ['state'],
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

    #[Test]
    public function life_group_snapshot_matches_unfiltered_bcc_summary(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        BCC::factory()->active()->count(2)->create(['tenant_id' => $tenant->id]);
        BCC::factory()->active()->create(['tenant_id' => $other->id]);
        Family::factory()->count(3)->create(['tenant_id' => $tenant->id, 'bcc_id' => null, 'status' => 'active']);
        $bcc = BCC::query()->where('tenant_id', $tenant->id)->first();
        Family::factory()->count(2)->create(['tenant_id' => $tenant->id, 'bcc_id' => $bcc->id, 'status' => 'active']);
        Family::factory()->count(5)->create(['tenant_id' => $other->id, 'bcc_id' => null, 'status' => 'active']);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view', 'bcc.view']);
        Passport::actingAs($user);

        $summary = app(BccDashboardService::class)->summary($tenant->id);
        $executive = $this->getJson('/api/tenant/dashboard/executive')->assertOk();
        $card = $executive->json('data.snapshot.data.cards.life_groups');

        $this->assertEquals($summary['snapshot']['bccs_active'], $card['active_bccs']);
        $this->assertEquals($summary['snapshot']['families_connected'], $card['families_connected']);
        $this->assertEquals($summary['snapshot']['families_without_bcc'], $card['families_without_bcc']);
        $this->assertEquals($summary['snapshot']['coverage_percent'], $card['household_coverage_percent']);
        $this->assertEquals(2, $card['active_bccs']);
        $this->assertEquals(2, $card['families_connected']);
        $this->assertEquals(3, $card['families_without_bcc']);
    }

    #[Test]
    public function celebration_counts_match_week_celebration_lists(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));

        $tenant = Tenant::factory()->create([
            'settings' => ['timezone' => 'UTC', 'language' => 'en'],
        ]);
        $family = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        FamilyMember::factory()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $family->id,
            'status' => 'active',
            'date_of_birth' => '2000-10-08',
            'marriage_date' => null,
        ]);
        $couple = Family::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        FamilyMember::factory()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $couple->id,
            'status' => 'active',
            'relationship_to_head' => 'self',
            'gender' => 'male',
            'date_of_birth' => '1980-01-02',
            'marriage_date' => '2010-10-08',
        ]);
        FamilyMember::factory()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $couple->id,
            'status' => 'active',
            'relationship_to_head' => 'spouse',
            'gender' => 'female',
            'date_of_birth' => '1982-03-03',
            'marriage_date' => '2010-10-08',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $service = app(MemberCelebrationsService::class);
        $full = $service->weekCelebrations($tenant->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $counts = $service->weekCelebrationCounts($tenant->id);
        $countQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(8, $countQueries);
        $this->assertSame(count($full['birthdays']), $counts['birthdays_count']);
        $this->assertSame(count($full['anniversaries']), $counts['anniversaries_count']);
        $this->assertSame(1, $counts['anniversaries_count']);

        $executive = $this->getJson('/api/tenant/dashboard/executive')->assertOk();
        $celebrations = $executive->json('data.celebrations.data');

        $this->assertSame(count($full['birthdays']), $celebrations['birthdays_count']);
        $this->assertSame(count($full['anniversaries']), $celebrations['anniversaries_count']);
        $this->assertSame($full['week']['start'], $celebrations['week_start']);
        $this->assertSame($full['week']['end'], $celebrations['week_end']);

        Carbon::setTestNow();
    }

    #[Test]
    public function executive_dashboard_does_not_repeat_family_count_queries(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/tenant/dashboard/executive')->assertOk();
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $familyTotals = $log->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "families"')
                && str_contains($sql, 'count(*)')
                && str_contains($sql, 'total_families');
        });

        $this->assertLessThanOrEqual(1, $familyTotals->count());
        $this->assertLessThan(120, $log->count());
    }

    #[Test]
    public function executive_dashboard_is_isolated_to_the_effective_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Family::factory()->count(4)->create(['tenant_id' => $tenantA->id, 'status' => 'active']);
        Family::factory()->count(9)->create(['tenant_id' => $tenantB->id, 'status' => 'active']);

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $this->grantUserPermissions($userA, ['families.view']);
        Passport::actingAs($userA);

        $this->getJson('/api/tenant/dashboard/executive')
            ->assertOk()
            ->assertJsonPath('data.snapshot.data.cards.families.total_families', 4);

        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $this->grantUserPermissions($userB, ['families.view']);
        Passport::actingAs($userB);

        $this->getJson('/api/tenant/dashboard/executive')
            ->assertOk()
            ->assertJsonPath('data.snapshot.data.cards.families.total_families', 9);
    }

    #[Test]
    public function snapshot_omits_sacraments_without_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive')->assertOk();
        $cards = $response->json('data.snapshot.data.cards') ?? [];
        $this->assertArrayNotHasKey('sacraments', $cards);
    }

    #[Test]
    public function sacraments_snapshot_is_year_to_date_mix_without_loading_register_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));

        $tenant = Tenant::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $other = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['sacraments.view']);

        $baptism = SacramentType::factory()->create(['name' => 'Baptism', 'code' => SacramentTypeCode::BAPTISM, 'active' => true]);
        $marriage = SacramentType::factory()->create(['name' => 'Marriage', 'code' => SacramentTypeCode::MATRIMONY, 'active' => true]);
        $reconciliation = SacramentType::factory()->create(['name' => 'Reconciliation', 'code' => SacramentTypeCode::RECONCILIATION, 'active' => true]);
        $anointing = SacramentType::factory()->create(['name' => 'Anointing of the Sick', 'code' => SacramentTypeCode::ANOINTING, 'active' => true]);

        Sacrament::factory()->registered()->count(4)->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $baptism->id,
            'date_administered' => '2026-03-15',
        ]);
        Sacrament::factory()->registered()->count(2)->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $marriage->id,
            'date_administered' => '2026-10-01',
        ]);
        Sacrament::factory()->registered()->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $baptism->id,
            'date_administered' => '2025-06-01',
        ]);
        Sacrament::factory()->voided()->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $baptism->id,
            'date_administered' => '2026-09-01',
            'status' => SacramentStatus::VOIDED,
        ]);
        Sacrament::factory()->registered()->count(9)->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $reconciliation->id,
            'date_administered' => '2026-04-01',
        ]);
        Sacrament::factory()->registered()->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $anointing->id,
            'date_administered' => '2026-05-01',
        ]);
        Sacrament::factory()->registered()->count(5)->create([
            'tenant_id' => $other->id,
            'sacrament_type_id' => $baptism->id,
            'date_administered' => '2026-03-15',
        ]);

        Passport::actingAs($user);

        $response = $this->getJson('/api/tenant/dashboard/executive')->assertOk();
        $card = $response->json('data.snapshot.data.cards.sacraments');

        $this->assertSame(7, $card['this_year']);
        $this->assertSame(7, $card['total_period']);
        $this->assertSame(2, $card['this_month']);
        $this->assertSame('Year to date', $card['period_label']);
        $this->assertSame('2026-01-01', $card['date_from']);
        $this->assertSame('2026-10-02', $card['date_to']);
        $this->assertSame(6, $card['activity_mix_total']);
        $this->assertSame('none', $card['activity_mix_empty_reason']);

        $codes = array_column($card['activity_mix'], 'code');
        $this->assertSame(['BAPTISM', 'MATRIMONY'], $codes);
        $this->assertSame(4, $card['activity_mix'][0]['count']);
        $this->assertSame(66.7, $card['activity_mix'][0]['percent']);
        $this->assertSame(2, $card['activity_mix'][1]['count']);
        $this->assertSame(33.3, $card['activity_mix'][1]['percent']);

        Carbon::setTestNow();
    }

    #[Test]
    public function sacraments_snapshot_includes_restricted_types_only_with_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));

        $tenant = Tenant::factory()->create(['settings' => ['timezone' => 'UTC']]);
        $reconciliation = SacramentType::factory()->create(['name' => 'Reconciliation', 'code' => SacramentTypeCode::RECONCILIATION, 'active' => true]);
        Sacrament::factory()->registered()->count(3)->create([
            'tenant_id' => $tenant->id,
            'sacrament_type_id' => $reconciliation->id,
            'date_administered' => '2026-02-01',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['sacraments.view']);
        Passport::actingAs($user);

        $without = $this->getJson('/api/tenant/dashboard/executive')->assertOk()
            ->json('data.snapshot.data.cards.sacraments');
        $this->assertSame(0, $without['this_year']);
        $this->assertSame(0, $without['activity_mix_total']);
        $this->assertSame('no_register_activity', $without['activity_mix_empty_reason']);

        $privileged = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($privileged, ['sacraments.view', 'sacraments.view_restricted']);
        Passport::actingAs($privileged);

        $with = $this->getJson('/api/tenant/dashboard/executive')->assertOk()
            ->json('data.snapshot.data.cards.sacraments');
        $this->assertSame(3, $with['this_year']);
        $this->assertSame(0, $with['activity_mix_total']);
        $this->assertSame('only_excluded_types', $with['activity_mix_empty_reason']);

        Carbon::setTestNow();
    }

    #[Test]
    public function ministries_bundle_returns_kpis_without_snapshot_cards(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['ministries_associations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['ministries.view', 'families.view']);
        Passport::actingAs($user);

        $ministries = $this->getJson('/api/tenant/dashboard/executive?bundle=ministries');
        $snapshot = $this->getJson('/api/tenant/dashboard/executive?bundle=snapshot');

        $ministries->assertOk()
            ->assertJsonPath('data.ministries.state', 'ready')
            ->assertJsonPath('data.ministries.data.drilldown', 'ministries.home')
            ->assertJsonMissingPath('data.snapshot');

        $this->assertArrayHasKey('active_groups_total', $ministries->json('data.ministries.data') ?? []);
        $this->assertArrayNotHasKey('groups_by_category', $ministries->json('data.ministries.data') ?? []);

        $cards = $snapshot->assertOk()->json('data.snapshot.data.cards') ?? [];
        $this->assertArrayNotHasKey('ministries', $cards);
    }

    #[Test]
    public function ministries_bundle_is_forbidden_without_permission(): void
    {
        $tenant = Tenant::factory()->create(['features' => ['ministries_associations']]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->grantUserPermissions($user, ['families.view']);
        Passport::actingAs($user);

        $this->getJson('/api/tenant/dashboard/executive?bundle=ministries')
            ->assertOk()
            ->assertJsonPath('data.ministries.state', 'forbidden')
            ->assertJsonMissingPath('data.ministries.data');
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
