<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\ParishProgressionFilter;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected SacramentType $baptismType;

    protected SacramentType $confirmationType;

    protected SacramentType $reconciliationType;

    protected SacramentType $matrimonyType;

    protected SacramentType $eucharistType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $role->id,
        ]);
        $this->user->syncRoles([$role->id]);
        $this->grantPermissions($role, ['sacraments.view']);

        $this->otherTenant = Tenant::factory()->create();
        $otherRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->otherTenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $this->otherUser = User::factory()->create([
            'tenant_id' => $this->otherTenant->id,
            'role_id' => $otherRole->id,
        ]);
        $this->otherUser->syncRoles([$otherRole->id]);
        $this->grantPermissions($otherRole, ['sacraments.view']);

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => SacramentTypeCode::BAPTISM,
            'active' => true,
        ]);

        $this->confirmationType = SacramentType::factory()->create([
            'name' => 'Confirmation',
            'code' => SacramentTypeCode::CONFIRMATION,
            'active' => true,
        ]);

        $this->reconciliationType = SacramentType::factory()->create([
            'name' => 'Reconciliation',
            'code' => SacramentTypeCode::RECONCILIATION,
            'active' => true,
        ]);

        $this->matrimonyType = SacramentType::factory()->create([
            'name' => 'Matrimony',
            'code' => SacramentTypeCode::MATRIMONY,
            'active' => true,
        ]);

        $this->eucharistType = SacramentType::factory()->create([
            'name' => 'First Communion',
            'code' => SacramentTypeCode::EUCHARIST,
            'active' => true,
        ]);

        Passport::actingAs($this->user);
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(Role $role, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => 'Sacraments',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
    }

    #[Test]
    public function it_returns_dashboard_summary_with_correct_counts(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'family_id' => $family->id,
            'date_administered' => now()->startOfYear()->addDays(5)->toDateString(),
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->confirmationType->id,
            'date_administered' => now()->startOfMonth()->toDateString(),
        ]);

        Sacrament::factory()->voided()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => now()->startOfYear()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kpis.total_period', 2)
            ->assertJsonPath('data.kpis.total_all_time', 2)
            ->assertJsonPath('data.breakdowns.member_status.member', 1)
            ->assertJsonPath('data.breakdowns.member_status.non_member', 1);

        $baptismRow = collect($response->json('data.kpis.by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismRow);
        $this->assertSame(1, $baptismRow['period']);
    }

    #[Test]
    public function it_excludes_voided_records_from_summary(): void
    {
        Sacrament::factory()->voided()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_all_time', 1);
    }

    #[Test]
    public function it_filters_summary_by_date_range(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2024-06-15',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_period', 1);
    }

    #[Test]
    public function it_enforces_tenant_isolation_on_dashboard_summary(): void
    {
        Sacrament::factory()->registered()->count(4)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        Sacrament::factory()->registered()->count(2)->create([
            'tenant_id' => $this->otherTenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_all_time', 4);
    }

    #[Test]
    public function it_excludes_restricted_sacrament_types_without_permission(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->reconciliationType->id,
            'date_administered' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_all_time', 1);

        $codes = collect($response->json('data.kpis.by_type'))->pluck('code')->all();
        $this->assertNotContains(SacramentTypeCode::RECONCILIATION, $codes);
        $this->assertContains(
            SacramentTypeCode::RECONCILIATION,
            $response->json('data.meta.restricted_types_excluded')
        );
    }

    #[Test]
    public function it_filters_dashboard_by_bcc(): void
    {
        $bcc = BCC::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherBcc = BCC::factory()->create(['tenant_id' => $this->tenant->id]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'bcc_id' => $bcc->id,
            'date_administered' => now()->toDateString(),
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'bcc_id' => $otherBcc->id,
            'date_administered' => now()->toDateString(),
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?bcc_id='.$bcc->id);

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_all_time', 1);
    }

    #[Test]
    public function it_returns_demographics_breakdowns(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
            'recipient_gender' => 'male',
            'recipient_birth_date' => '2024-01-15',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-07-20',
            'recipient_gender' => 'female',
            'recipient_birth_date' => '2024-03-20',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_all_time', 2)
            ->assertJsonPath('data.kpis.total_period', 2);

        $baptismGender = collect($response->json('data.demographics.gender_by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismGender);
        $this->assertSame(1, $baptismGender['male']);
        $this->assertSame(1, $baptismGender['female']);

        $baptismAge = collect($response->json('data.demographics.age_by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismAge);
        $this->assertSame(2, $baptismAge['with_age_data']);

        $this->assertGreaterThan(0, collect($response->json('data.demographics.age_buckets'))
            ->sum('count'));
    }

    #[Test]
    public function it_excludes_reconciliation_and_anointing_from_age_distribution(): void
    {
        $anointingType = SacramentType::factory()->create([
            'name' => 'Anointing of the Sick',
            'code' => SacramentTypeCode::ANOINTING,
            'active' => true,
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
            'recipient_gender' => 'male',
            'recipient_birth_date' => '2020-01-15',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $anointingType->id,
            'date_administered' => '2025-06-20',
            'recipient_gender' => 'female',
            'recipient_birth_date' => '1950-05-10',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->reconciliationType->id,
            'date_administered' => '2025-06-25',
            'recipient_gender' => 'male',
            'recipient_birth_date' => '1990-08-01',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk();

        $ageCodes = collect($response->json('data.demographics.age_by_type'))->pluck('code')->all();
        $this->assertContains(SacramentTypeCode::BAPTISM, $ageCodes);
        $this->assertNotContains(SacramentTypeCode::RECONCILIATION, $ageCodes);
        $this->assertNotContains(SacramentTypeCode::ANOINTING, $ageCodes);

        $genderCodes = collect($response->json('data.demographics.gender_by_type'))->pluck('code')->all();
        $this->assertContains(SacramentTypeCode::BAPTISM, $genderCodes);
        $this->assertContains(SacramentTypeCode::ANOINTING, $genderCodes);
        $this->assertNotContains(SacramentTypeCode::RECONCILIATION, $genderCodes);

        $this->assertSame(1, collect($response->json('data.demographics.age_buckets'))->sum('count'));

        $trendCodes = collect($response->json('data.trends.series'))->pluck('code')->all();
        $this->assertContains(SacramentTypeCode::BAPTISM, $trendCodes);
        $this->assertNotContains(SacramentTypeCode::RECONCILIATION, $trendCodes);
        $this->assertNotContains(SacramentTypeCode::ANOINTING, $trendCodes);
    }

    #[Test]
    public function it_returns_matrimony_insights(): void
    {
        $marriage = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-08-20',
            'marriage_canonical_classification' => 'mixed_marriage',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Joseph Parish',
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'bride',
            'source' => 'external',
            'external_full_name' => 'Jane Bride',
            'external_date_of_birth' => '1998-05-10',
            'external_gender' => 'female',
            'affiliation_parish_name' => 'St. Mary Parish',
            'snapshot_json' => [],
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'groom',
            'source' => 'external',
            'external_full_name' => 'John Groom',
            'external_date_of_birth' => '1995-03-15',
            'external_gender' => 'male',
            'affiliation_parish_name' => 'St. Joseph Parish',
            'snapshot_json' => [],
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.matrimony.count', 1)
            ->assertJsonPath('data.matrimony.inter_parish_count', 1)
            ->assertJsonPath('data.matrimony.canonical_classification.mixed_marriage', 1);

        $this->assertNotNull($response->json('data.matrimony.bride_avg_age'));
        $this->assertNotNull($response->json('data.matrimony.groom_avg_age'));
        $this->assertSame(27.0, (float) $response->json('data.matrimony.bride_avg_age'));
        $this->assertSame(30.0, (float) $response->json('data.matrimony.groom_avg_age'));
        $this->assertSame(1, $response->json('data.matrimony.bride_with_age'));
        $this->assertSame(1, $response->json('data.matrimony.groom_with_age'));
    }

    #[Test]
    public function it_returns_matrimony_insights_for_member_linked_participants(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $bride = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2000-10-15',
            'gender' => 'female',
        ]);
        $groom = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'date_of_birth' => '1998-03-20',
            'gender' => 'male',
        ]);

        $marriage = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2026-09-04',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Joseph Parish',
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'bride',
            'source' => 'member',
            'family_member_id' => $bride->id,
            'person_id' => $bride->person_id,
            'external_date_of_birth' => null,
            'affiliation_parish_name' => 'St. Mary Parish',
            'snapshot_json' => ['date_of_birth' => '2000-10-15'],
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'groom',
            'source' => 'member',
            'family_member_id' => $groom->id,
            'person_id' => $groom->person_id,
            'external_date_of_birth' => null,
            'affiliation_parish_name' => 'St. Joseph Parish',
            'snapshot_json' => ['date_of_birth' => '1998-03-20'],
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2026-01-01',
            'date_to' => '2026-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.matrimony.count', 1)
            ->assertJsonPath('data.matrimony.bride_with_age', 1)
            ->assertJsonPath('data.matrimony.groom_with_age', 1)
            ->assertJsonPath('data.matrimony.bride_missing_dob', 0)
            ->assertJsonPath('data.matrimony.groom_missing_dob', 0);

        $this->assertSame(25.0, (float) $response->json('data.matrimony.bride_avg_age'));
        $this->assertSame(28.0, (float) $response->json('data.matrimony.groom_avg_age'));

        $brideBuckets = collect($response->json('data.matrimony.age_brackets.bride'));
        $this->assertSame(1, $brideBuckets->firstWhere('key', '18_25')['count'] ?? 0);
    }

    #[Test]
    public function it_tracks_missing_dob_for_matrimony_participants_without_birth_dates(): void
    {
        $marriage = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-08-20',
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'bride',
            'source' => 'external',
            'external_full_name' => 'Jane Bride',
            'external_gender' => 'female',
            'snapshot_json' => [],
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $marriage->id,
            'role' => 'groom',
            'source' => 'external',
            'external_full_name' => 'John Groom',
            'external_gender' => 'male',
            'snapshot_json' => [],
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.matrimony.count', 1)
            ->assertJsonPath('data.matrimony.bride_avg_age', null)
            ->assertJsonPath('data.matrimony.groom_avg_age', null)
            ->assertJsonPath('data.matrimony.bride_missing_dob', 1)
            ->assertJsonPath('data.matrimony.groom_missing_dob', 1);
    }

    #[Test]
    public function it_returns_all_time_summary_when_no_date_filters_are_provided(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2020-06-16',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Joseph Parish',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.period.label', 'All time')
            ->assertJsonPath('data.matrimony.count', 1);
    }

    #[Test]
    public function it_returns_participation_gap_analysis(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2010-06-15',
            'baptism_date' => '2010-08-01',
            'first_communion_date' => null,
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2018-06-15',
            'baptism_date' => null,
            'first_communion_date' => null,
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk()
            ->assertJsonPath('data.gaps.eligible_members', 2)
            ->assertJsonPath('data.gaps.thresholds.progression', 10);

        $baptismGap = collect($response->json('data.gaps.by_sacrament'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismGap);
        $this->assertSame(2, $baptismGap['eligible_count']);
        $this->assertSame(1, $baptismGap['received_count']);
        $this->assertSame(1, $baptismGap['missing_count']);
        $this->assertSame(50.0, (float) $baptismGap['participation_pct']);

        $eucharistGap = collect($response->json('data.gaps.by_sacrament'))
            ->firstWhere('code', SacramentTypeCode::EUCHARIST);
        $this->assertNotNull($eucharistGap);
        $this->assertSame(2, $eucharistGap['eligible_count']);
        $this->assertSame(0, $eucharistGap['received_count']);
        $this->assertSame(2, $eucharistGap['missing_count']);

        $this->assertSame(
            1,
            $response->json('data.gaps.progression.baptized_without_communion.count')
        );
        $this->assertSame(
            1,
            $response->json('data.gaps.progression.baptized_without_confirmation.count')
        );
    }

    #[Test]
    public function it_aligns_progression_counts_with_member_list_filters(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $needsCommunion = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-01-15',
            'baptism_date' => '2015-03-01',
            'first_communion_date' => null,
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        $needsConfirmationOnly = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2015-06-01',
            'baptism_date' => '2015-08-01',
            'first_communion_date' => '2023-05-01',
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2020-01-15',
            'baptism_date' => '2020-03-01',
            'first_communion_date' => null,
            'confirmation_date' => null,
            'deceased_date' => null,
        ]);

        $dashboard = $this->getJson('/api/sacraments/dashboard/summary');

        $dashboard->assertOk()
            ->assertJsonPath('data.gaps.progression.baptized_without_communion.count', 1)
            ->assertJsonPath('data.gaps.progression.baptized_without_confirmation.count', 2);

        $communionMembers = $this->getJson('/api/members?'.http_build_query([
            'progression' => ParishProgressionFilter::BAPTIZED_WITHOUT_COMMUNION,
            'per_page' => 100,
        ]));
        $communionMembers->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $needsCommunion->id);

        $confirmationMembers = $this->getJson('/api/members?'.http_build_query([
            'progression' => ParishProgressionFilter::BAPTIZED_WITHOUT_CONFIRMATION,
            'per_page' => 100,
        ]));
        $confirmationMembers->assertOk()
            ->assertJsonPath('total', 2);

        $confirmationIds = collect($confirmationMembers->json('data'))->pluck('id')->all();
        $this->assertContains($needsCommunion->id, $confirmationIds);
        $this->assertContains($needsConfirmationOnly->id, $confirmationIds);
    }

    #[Test]
    public function it_counts_unmarried_age_cohorts_and_excludes_holy_orders(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $holyOrdersType = SacramentType::factory()->create([
            'name' => 'Holy Orders',
            'code' => SacramentTypeCode::HOLY_ORDERS,
            'active' => true,
        ]);

        $unmarriedWoman = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'married',
            'date_of_birth' => now()->subYears(26)->toDateString(),
            'deceased_date' => null,
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $unmarriedMan = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $ordainedMan = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(40)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(23)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $holyOrdersType->id,
            'person_id' => $ordainedMan->person_id,
            'date_administered' => now()->subYears(10)->toDateString(),
        ]);

        $dashboard = $this->getJson('/api/sacraments/dashboard/summary');

        $dashboard->assertOk()
            ->assertJsonPath('data.gaps.progression.female_unmarried_over_18.count', 1)
            ->assertJsonPath('data.gaps.progression.male_unmarried_over_23.count', 1);

        $femaleMembers = $this->getJson('/api/members?'.http_build_query([
            'progression' => ParishProgressionFilter::FEMALE_UNMARRIED_OVER_18,
            'per_page' => 100,
        ]));
        $femaleMembers->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $unmarriedWoman->id);

        $maleMembers = $this->getJson('/api/members?'.http_build_query([
            'progression' => ParishProgressionFilter::MALE_UNMARRIED_OVER_23,
            'per_page' => 100,
        ]));
        $maleMembers->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $unmarriedMan->id);
    }

    #[Test]
    public function it_omits_gaps_when_include_gaps_is_false(): void
    {
        $response = $this->getJson('/api/sacraments/dashboard/summary?include_gaps=0');

        $response->assertOk();
        $this->assertArrayNotHasKey('gaps', $response->json('data'));
    }

    #[Test]
    public function it_counts_sacrament_register_receipt_for_gap_analysis(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $member = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'date_of_birth' => '2018-06-15',
            'baptism_date' => null,
            'deceased_date' => null,
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'family_id' => $family->id,
            'person_id' => $member->person_id,
            'date_administered' => '2025-06-15',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary');

        $response->assertOk();

        $baptismGap = collect($response->json('data.gaps.by_sacrament'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismGap);
        $this->assertSame(1, $baptismGap['eligible_count']);
        $this->assertSame(1, $baptismGap['received_count']);
        $this->assertSame(0, $baptismGap['missing_count']);
        $this->assertSame(100.0, (float) $baptismGap['participation_pct']);
    }

    #[Test]
    public function it_caches_dashboard_summary_responses(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => now()->toDateString(),
        ]);

        $first = $this->getJson('/api/sacraments/dashboard/summary');
        $first->assertOk()
            ->assertJsonPath('data.meta.cached', false);
        $this->assertNotNull($first->json('data.meta.duration_ms'));

        $second = $this->getJson('/api/sacraments/dashboard/summary');
        $second->assertOk()
            ->assertJsonPath('data.meta.cached', true)
            ->assertJsonPath('data.kpis.total_all_time', $first->json('data.kpis.total_all_time'));
    }

    #[Test]
    public function it_rejects_ekklesia_users_from_dashboard_summary(): void
    {
        $role = Role::query()->firstOrCreate(
            ['name' => Role::EKKLESIA_ADMIN, 'tenant_id' => null],
            [
                'description' => 'Ekklesia Admin',
                'level' => 2,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $ekklesiaUser = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
        ]);
        $ekklesiaUser->syncRoles([$role->id]);

        Passport::actingAs($ekklesiaUser);

        $this->getJson('/api/sacraments/dashboard/summary')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function it_calculates_leap_year_ages_in_demographics(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2024-02-29',
            'recipient_gender' => 'male',
            'recipient_birth_date' => '2000-02-29',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2024-01-01',
            'date_to' => '2024-12-31',
        ]));

        $response->assertOk();

        $baptismAge = collect($response->json('data.demographics.age_by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismAge);
        $this->assertSame(24.0, (float) $baptismAge['average_age']);
    }

    #[Test]
    public function it_counts_repeatable_eucharist_events_in_period_totals(): void
    {
        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);

        Sacrament::factory()->registered()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->eucharistType->id,
            'family_id' => $family->id,
            'date_administered' => '2025-06-15',
            'recipient_name' => 'Same Recipient',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.kpis.total_period', 2);

        $eucharistRow = collect($response->json('data.kpis.by_type'))
            ->firstWhere('code', SacramentTypeCode::EUCHARIST);
        $this->assertNotNull($eucharistRow);
        $this->assertSame(2, $eucharistRow['period']);
    }

    #[Test]
    public function it_uses_external_participant_birth_date_for_demographics(): void
    {
        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
            'recipient_birth_date' => null,
            'recipient_gender' => null,
        ]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $sacrament->id,
            'role' => 'recipient',
            'source' => 'external',
            'external_full_name' => 'External Child',
            'external_date_of_birth' => '2018-03-10',
            'external_gender' => 'female',
            'snapshot_json' => [],
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk();

        $baptismGender = collect($response->json('data.demographics.gender_by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismGender);
        $this->assertSame(1, $baptismGender['female']);

        $baptismAge = collect($response->json('data.demographics.age_by_type'))
            ->firstWhere('code', SacramentTypeCode::BAPTISM);
        $this->assertNotNull($baptismAge);
        $this->assertSame(1, $baptismAge['with_age_data']);
    }

    #[Test]
    public function it_matches_register_eligible_counts_for_period(): void
    {
        Sacrament::factory()->registered()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->confirmationType->id,
            'date_administered' => '2025-07-01',
        ]);

        Sacrament::factory()->voided()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-20',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk();

        $expected = Sacrament::query()
            ->forTenant($this->tenant->id)
            ->whereIn('status', [SacramentStatus::REGISTERED, SacramentStatus::CONDITIONAL])
            ->whereBetween('date_administered', ['2025-01-01', '2025-12-31'])
            ->count();

        $this->assertSame($expected, $response->json('data.kpis.total_period'));
        $this->assertSame(3, $expected);
    }

    #[Test]
    public function it_reports_query_duration_on_dashboard_summary(): void
    {
        Sacrament::factory()->registered()->count(15)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2025-06-15',
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
            'include_gaps' => false,
        ]));

        $response->assertOk()
            ->assertJsonPath('data.meta.cached', false);

        $durationMs = (float) $response->json('data.meta.duration_ms');
        $this->assertGreaterThan(0, $durationMs);
        $this->assertLessThan(5000, $durationMs);
    }

    #[Test]
    public function it_returns_canonical_breakdown_on_matrimony_payload(): void
    {
        $bothCatholic = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2024-01-10',
            'marriage_canonical_classification' => 'both_catholic',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Mary Parish',
        ]);

        $mixed = Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-08-20',
            'marriage_canonical_classification' => 'mixed_marriage',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Joseph Parish',
        ]);

        $family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $bride = FamilyMember::factory()->create(['family_id' => $family->id]);
        $groom = FamilyMember::factory()->create(['family_id' => $family->id]);

        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $bothCatholic->id,
            'role' => 'bride',
            'source' => 'member',
            'family_member_id' => $bride->id,
            'snapshot_json' => [],
        ]);
        SacramentParticipant::query()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_id' => $bothCatholic->id,
            'role' => 'groom',
            'source' => 'member',
            'family_member_id' => $groom->id,
            'snapshot_json' => [],
        ]);

        $response = $this->getJson('/api/sacraments/dashboard/summary?'.http_build_query([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.matrimony.canonical_breakdown.total_recorded', 2)
            ->assertJsonPath('data.matrimony.canonical_breakdown.metrics.catholic_both.count', 1)
            ->assertJsonPath('data.matrimony.canonical_breakdown.metrics.mixed_disparity.count', 1)
            ->assertJsonPath('data.matrimony.canonical_breakdown.metrics.same_parish.count', 1)
            ->assertJsonPath('data.matrimony.canonical_breakdown.metrics.inter_parish.count', 1);
    }

    #[Test]
    public function it_filters_sacrament_register_by_marriage_parish_origin_filter(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-03-10',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Mary Parish',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-04-12',
            'marriage_bride_church_name' => 'St. Mary Parish',
            'marriage_groom_church_name' => 'St. Joseph Parish',
        ]);

        $sameParish = $this->getJson('/api/sacraments?'.http_build_query([
            'sacrament_type_id' => $this->matrimonyType->id,
            'marriage_register_filter' => 'same_parish',
        ]));

        $sameParish->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.marriage_bride_church_name', 'St. Mary Parish')
            ->assertJsonPath('data.data.0.marriage_groom_church_name', 'St. Mary Parish');

        $interParish = $this->getJson('/api/sacraments?'.http_build_query([
            'sacrament_type_id' => $this->matrimonyType->id,
            'marriage_register_filter' => 'inter_parish',
        ]));

        $interParish->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.marriage_groom_church_name', 'St. Joseph Parish');
    }

    #[Test]
    public function it_filters_sacrament_register_by_marriage_register_filter(): void
    {
        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-03-10',
            'marriage_canonical_classification' => 'both_catholic',
        ]);

        Sacrament::factory()->registered()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->matrimonyType->id,
            'date_administered' => '2025-04-12',
            'marriage_canonical_classification' => 'mixed_marriage',
        ]);

        $response = $this->getJson('/api/sacraments?'.http_build_query([
            'sacrament_type_id' => $this->matrimonyType->id,
            'marriage_register_filter' => 'catholic_both',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.marriage_canonical_classification', 'both_catholic');
    }
}
