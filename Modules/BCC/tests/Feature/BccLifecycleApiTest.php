<?php

namespace Modules\BCC\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

class BccLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected BCC $bcc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->bcc = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        Passport::actingAs($this->user);
        $this->grantBccPermissions();
    }

    public function test_dashboard_is_tenant_scoped(): void
    {
        $other = Tenant::factory()->create();
        BCC::factory()->count(2)->create(['tenant_id' => $other->id, 'status' => 'active']);

        $response = $this->getJson('/api/bccs/dashboard');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertEquals(1, $response->json('data.bccs.total'));
        $this->assertEquals(1, $response->json('data.snapshot.bccs_total'));
        $this->assertArrayHasKey('coverage', $response->json('data'));
        $this->assertArrayHasKey('growth', $response->json('data'));
        $this->assertArrayHasKey('attention', $response->json('data'));
        $this->assertArrayHasKey('definitions', $response->json('data'));
    }

    public function test_dashboard_period_does_not_change_snapshot_counts(): void
    {
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);
        Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
            'status' => 'active',
        ]);

        $year = $this->getJson('/api/bccs/dashboard?period=1y');
        $quarter = $this->getJson('/api/bccs/dashboard?period=3m');

        $year->assertOk();
        $quarter->assertOk();
        $this->assertEquals(
            $year->json('data.snapshot.families_connected'),
            $quarter->json('data.snapshot.families_connected')
        );
        $this->assertEquals(
            $year->json('data.coverage.linked'),
            $quarter->json('data.coverage.linked')
        );
    }

    public function test_overview_counts_unique_members_and_unknown_gender(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'status' => 'active',
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'inactive',
            'date_of_birth' => null,
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'status' => 'active',
            'date_of_birth' => now()->subYears(1)->toDateString(),
        ]);

        $response = $this->getJson("/api/bccs/{$this->bcc->id}/dashboard");

        $response->assertOk();
        $this->assertEquals(3, $response->json('data.total_members'));
        $this->assertEquals(1, $response->json('data.total_families'));
        $this->assertEquals(2, $response->json('data.active_members'));
        $this->assertEquals(1, $response->json('data.inactive_members'));
        $this->assertEquals(1, $response->json('data.gender.male.count'));
        $this->assertEquals(1, $response->json('data.gender.female.count'));
        $this->assertEquals(1, $response->json('data.gender.unknown.count'));
        $this->assertEquals(1, $response->json('data.babies.total'));
        $this->assertNull($response->json('data.occupation'));
        $this->assertNull($response->json('data.education'));

        $genderTotal = $response->json('data.gender.male.count')
            + $response->json('data.gender.female.count')
            + $response->json('data.gender.other.count')
            + $response->json('data.gender.unknown.count');
        $this->assertEquals(3, $genderTotal);

        $ageTotal = collect($response->json('data.age_groups'))->sum('count');
        $this->assertEquals(3, $ageTotal);

        $statusTotal = $response->json('data.membership_status.active')
            + $response->json('data.membership_status.inactive')
            + $response->json('data.membership_status.deceased')
            + $response->json('data.membership_status.migrated');
        $this->assertEquals(3, $statusTotal);

        $this->assertArrayHasKey('attention', $response->json('data'));
        $this->assertArrayHasKey('data_quality', $response->json('data'));
        $this->assertArrayHasKey('growth', $response->json('data'));
        $this->assertEquals(2, $response->json('data.data_quality.complete_count'));
        $this->assertEquals(1, $response->json('data.data_quality.incomplete_count'));
        $this->assertEquals(3, $response->json('data.data_quality.total'));
        $this->assertNotEmpty($response->json('data.data_quality.definition'));
        $this->assertTrue($response->json('data.growth.insufficient_history'));

        $attentionCodes = collect($response->json('data.attention'))->pluck('code')->all();
        $this->assertContains('no_primary', $attentionCodes);
        $this->assertContains('incomplete_demographics', $attentionCodes);
        $this->assertNotEmpty($response->json('data.bcc.created_at'));
    }

    public function test_overview_empty_bcc_attention_and_complete_demographics(): void
    {
        $empty = $this->getJson("/api/bccs/{$this->bcc->id}/dashboard");
        $empty->assertOk();
        $this->assertEquals(0, $empty->json('data.total_members'));
        $codes = collect($empty->json('data.attention'))->pluck('code')->all();
        $this->assertContains('empty', $codes);
        $this->assertContains('no_primary', $codes);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'status' => 'active',
            'date_of_birth' => now()->subYears(40)->toDateString(),
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'status' => 'active',
            'date_of_birth' => now()->subYears(35)->toDateString(),
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $member = FamilyMember::query()->where('family_id', $family->id)->first();
        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $member->id,
            'role' => 'leader',
            'appointed_date' => now()->toDateString(),
        ])->assertCreated();

        $complete = $this->getJson("/api/bccs/{$this->bcc->id}/dashboard");
        $complete->assertOk();
        $this->assertEquals(2, $complete->json('data.data_quality.complete_count'));
        $this->assertEquals(0, $complete->json('data.data_quality.incomplete_count'));
        $this->assertTrue($complete->json('data.leadership.has_primary'));
        $attentionCodes = collect($complete->json('data.attention'))->pluck('code')->all();
        $this->assertNotContains('no_primary', $attentionCodes);
        $this->assertNotContains('incomplete_demographics', $attentionCodes);
        $this->assertNotContains('empty', $attentionCodes);
        $this->assertDatabaseHas('bcc_family_memberships', [
            'bcc_id' => $this->bcc->id,
            'family_id' => $family->id,
            'is_current' => true,
        ]);
        $this->assertFalse($complete->json('data.growth.insufficient_history'));
        $this->assertNotEmpty($complete->json('data.growth.members'));
        $familiesSeries = $complete->json('data.growth.families');
        $this->assertNotEmpty($familiesSeries);
        $lastFamilyPoint = $familiesSeries[count($familiesSeries) - 1];
        $this->assertGreaterThan(0, $lastFamilyPoint['value']);
    }

    public function test_overview_missing_gender_or_dob_counts_incomplete(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'active',
            'date_of_birth' => now()->subYears(20)->toDateString(),
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'status' => 'active',
            'date_of_birth' => null,
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'active',
            'date_of_birth' => now()->subYears(8)->toDateString(),
        ]);

        $response = $this->getJson("/api/bccs/{$this->bcc->id}/dashboard");
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.data_quality.complete_count'));
        $this->assertEquals(3, $response->json('data.data_quality.incomplete_count'));
        $this->assertEquals(2, $response->json('data.data_quality.missing_gender_count'));
        $this->assertEquals(1, $response->json('data.data_quality.missing_dob_count'));
    }

    public function test_assign_prevents_duplicate_and_supports_transfer(): void
    {
        $other = BCC::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertStatus(422);

        $conflict = $this->postJson("/api/bccs/{$other->id}/members", [
            'family_ids' => [$family->id],
        ]);
        $conflict->assertStatus(409);

        $this->postJson("/api/bccs/{$other->id}/members", [
            'family_ids' => [$family->id],
            'transfer' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('families', [
            'id' => $family->id,
            'bcc_id' => $other->id,
        ]);
    }

    public function test_cross_tenant_overview_is_not_found(): void
    {
        $other = Tenant::factory()->create();
        $foreign = BCC::factory()->create(['tenant_id' => $other->id, 'status' => 'active']);

        $this->getJson("/api/bccs/{$foreign->id}/dashboard")->assertNotFound();
    }

    public function test_unauthorized_user_cannot_view_bccs(): void
    {
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Passport::actingAs($plain);

        $this->getJson('/api/bccs/dashboard')->assertForbidden();
    }

    public function test_people_list_filters_by_status_gender_and_age_band(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);
        $adultMale = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'status' => 'active',
            'date_of_birth' => now()->subYears(35)->toDateString(),
        ]);
        $baby = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'status' => 'active',
            'date_of_birth' => now()->subYears(1)->toDateString(),
        ]);
        $inactiveUnknownGender = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'inactive',
            'date_of_birth' => null,
        ]);
        // Blank gender must behave like "not recorded" so the dashboard
        // drill-down returns exactly the members it counted as unknown.
        $blankGender = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'active',
            'date_of_birth' => now()->subYears(70)->toDateString(),
        ]);

        $active = $this->getJson("/api/bccs/{$this->bcc->id}/people?status=active");
        $active->assertOk();
        $this->assertEquals(3, $active->json('meta.total'));

        $males = $this->getJson("/api/bccs/{$this->bcc->id}/people?gender=male");
        $males->assertOk();
        $this->assertEquals(1, $males->json('meta.total'));
        $this->assertEquals($adultMale->id, $males->json('data.0.id'));

        $unknownGender = $this->getJson("/api/bccs/{$this->bcc->id}/people?gender=unknown");
        $unknownGender->assertOk();
        $this->assertEquals(2, $unknownGender->json('meta.total'));
        $unknownIds = collect($unknownGender->json('data'))->pluck('id')->all();
        $this->assertContains($inactiveUnknownGender->id, $unknownIds);
        $this->assertContains($blankGender->id, $unknownIds);

        $babies = $this->getJson("/api/bccs/{$this->bcc->id}/people?age_band=babies");
        $babies->assertOk();
        $this->assertEquals(1, $babies->json('meta.total'));
        $this->assertEquals($baby->id, $babies->json('data.0.id'));

        $missingDob = $this->getJson("/api/bccs/{$this->bcc->id}/people?age_band=unknown");
        $missingDob->assertOk();
        $this->assertEquals(1, $missingDob->json('meta.total'));
        $this->assertEquals($inactiveUnknownGender->id, $missingDob->json('data.0.id'));

        $combined = $this->getJson("/api/bccs/{$this->bcc->id}/people?status=active&gender=female&age_band=babies");
        $combined->assertOk();
        $this->assertEquals(1, $combined->json('meta.total'));

        $this->getJson("/api/bccs/{$this->bcc->id}/people?age_band=nonsense")
            ->assertStatus(422);
    }

    public function test_people_unknown_gender_filter_matches_dashboard_count(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => $this->bcc->id,
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $family->id,
            'gender' => 'male',
            'status' => 'active',
            'date_of_birth' => now()->subYears(40)->toDateString(),
        ]);
        FamilyMember::factory()->count(2)->create([
            'family_id' => $family->id,
            'gender' => null,
            'status' => 'active',
            'date_of_birth' => null,
        ]);

        $overview = $this->getJson("/api/bccs/{$this->bcc->id}/dashboard");
        $overview->assertOk();

        $drilldown = $this->getJson("/api/bccs/{$this->bcc->id}/people?gender=unknown");
        $drilldown->assertOk();

        $this->assertEquals(
            $overview->json('data.gender.unknown.count'),
            $drilldown->json('meta.total')
        );
    }

    public function test_family_list_filters_by_family_status(): void
    {
        foreach (['active', 'inactive'] as $status) {
            $family = Family::factory()->create([
                'tenant_id' => $this->tenant->id,
                'bcc_id' => $this->bcc->id,
                'status' => $status,
            ]);
            app(BccFamilyMembershipService::class)
                ->syncFromFamilyPointer($family, null, (int) $this->tenant->id);
        }

        $all = $this->getJson("/api/bccs/{$this->bcc->id}/members");
        $all->assertOk();
        $this->assertEquals(2, $all->json('meta.total'));

        $inactive = $this->getJson("/api/bccs/{$this->bcc->id}/members?status=inactive");
        $inactive->assertOk();
        $this->assertEquals(1, $inactive->json('meta.total'));
        $this->assertEquals('inactive', $inactive->json('data.0.family.status'));

        $this->getJson("/api/bccs/{$this->bcc->id}/members?status=deceased")
            ->assertStatus(422);
    }

    private function grantBccPermissions(): void
    {
        $ids = [];
        foreach ([
            'bcc.view',
            'bcc.create',
            'bcc.edit',
            'bcc.delete',
            'bcc.manage_members',
            'bcc.manage_leadership',
        ] as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'BCC',
                    'category' => 'bcc',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }
        $this->user->permissions()->syncWithoutDetaching($ids);
        $this->user->clearPermissionsCache();
    }

    public function test_leadership_rejects_non_member_and_duplicate_primary(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $outsider = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $outsider->id,
            'role' => 'leader',
        ])->assertStatus(422);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $outsider->id,
            'role' => 'leader',
            'appointed_date' => now()->toDateString(),
        ])->assertCreated();

        $second = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $second->id,
            'role' => 'leader',
        ])->assertStatus(409);

        $this->getJson("/api/bccs/{$this->bcc->id}/leadership/current")
            ->assertOk()
            ->assertJsonPath('data.active_count', 1);
    }

    public function test_leadership_term_lifecycle_persists_term_fields_and_exit_reason(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $appointment = now()->subDays(10)->toDateString();
        $effectiveFrom = now()->subDays(5)->toDateString();

        $assign = $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $member->id,
            'role' => 'leader',
            'appointment_date' => $appointment,
            'effective_from' => $effectiveFrom,
            'term_label' => '2026–2028',
            'appointment_reference' => 'Minutes 2026/08',
            'is_interim' => true,
            'remarks' => 'Interim until election.',
        ]);
        $assign->assertCreated();
        $assign->assertJsonPath('data.appointment_date', $appointment);
        $assign->assertJsonPath('data.effective_from', $effectiveFrom);
        $assign->assertJsonPath('data.term_label', '2026–2028');
        $assign->assertJsonPath('data.appointment_reference', 'Minutes 2026/08');
        $assign->assertJsonPath('data.is_interim', true);
        $assign->assertJsonPath('data.status', 'active');

        $leaderId = $assign->json('data.id');

        // Effective from before appointment date is rejected.
        $other = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);
        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $other->id,
            'role' => 'assistant',
            'appointment_date' => now()->toDateString(),
            'effective_from' => now()->subDays(3)->toDateString(),
        ])->assertStatus(422);

        // End date before term start is rejected.
        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/{$leaderId}/terminate", [
            'effective_to' => now()->subDays(30)->toDateString(),
            'exit_reason' => 'resigned',
        ])->assertStatus(422);

        $end = $this->postJson("/api/bccs/{$this->bcc->id}/leadership/{$leaderId}/terminate", [
            'effective_to' => now()->toDateString(),
            'exit_reason' => 'term_completed',
            'remarks' => 'Term completed normally.',
        ]);
        $end->assertOk();
        $end->assertJsonPath('data.is_active', false);
        $end->assertJsonPath('data.status', 'completed');
        $end->assertJsonPath('data.exit_reason', 'term_completed');
        $end->assertJsonPath('data.effective_to', now()->toDateString());

        $this->getJson("/api/bccs/{$this->bcc->id}/leadership/current")
            ->assertOk()
            ->assertJsonPath('data.active_count', 0);
    }

    public function test_leadership_handover_closes_outgoing_and_assigns_incoming_bcc_member(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);
        $outgoingMember = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);
        $incomingMember = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $assign = $this->postJson("/api/bccs/{$this->bcc->id}/leadership/assign", [
            'family_member_id' => $outgoingMember->id,
            'role' => 'leader',
            'appointment_date' => now()->subDays(20)->toDateString(),
            'effective_from' => now()->subDays(20)->toDateString(),
        ]);
        $assign->assertCreated();
        $outgoingId = $assign->json('data.id');

        $this->postJson("/api/bccs/{$this->bcc->id}/leadership/handover", [
            'outgoing_leader_id' => $outgoingId,
            'incoming_family_member_id' => $outgoingMember->id,
        ])->assertStatus(422);

        $handover = $this->postJson("/api/bccs/{$this->bcc->id}/leadership/handover", [
            'outgoing_leader_id' => $outgoingId,
            'incoming_family_member_id' => $incomingMember->id,
            'outgoing_effective_to' => now()->toDateString(),
            'outgoing_exit_reason' => 'resigned',
            'appointment_date' => now()->toDateString(),
            'effective_from' => now()->toDateString(),
            'term_label' => '2026–2028',
            'is_interim' => true,
        ]);
        $handover->assertOk();
        $handover->assertJsonPath('data.outgoing.is_active', false);
        $handover->assertJsonPath('data.outgoing.status', 'vacated');
        $handover->assertJsonPath('data.outgoing.exit_reason', 'resigned');
        $handover->assertJsonPath('data.incoming.is_active', true);
        $handover->assertJsonPath('data.incoming.status', 'active');
        $handover->assertJsonPath('data.incoming.family_member_id', $incomingMember->id);
        $handover->assertJsonPath('data.incoming.term_label', '2026–2028');
        $handover->assertJsonPath('data.incoming.is_interim', true);

        $current = $this->getJson("/api/bccs/{$this->bcc->id}/leadership/current");
        $current->assertOk();
        $current->assertJsonPath('data.active_count', 1);
        $current->assertJsonPath('data.leaders.0.family_member_id', $incomingMember->id);
    }

    public function test_eligible_leaders_are_scoped_to_bcc_and_include_family_code(): void
    {
        $inFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_code' => 'FAM000005',
        ]);
        $inMember = FamilyMember::factory()->create([
            'family_id' => $inFamily->id,
            'status' => 'active',
            'first_name' => 'Agnes',
            'last_name' => 'Stephen',
        ]);

        $outFamily = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'family_code' => 'FAM009999',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $outFamily->id,
            'status' => 'active',
        ]);

        $otherTenant = Tenant::factory()->create();
        $foreignFamily = Family::factory()->create([
            'tenant_id' => $otherTenant->id,
            'status' => 'active',
        ]);
        FamilyMember::factory()->create([
            'family_id' => $foreignFamily->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$inFamily->id],
        ])->assertCreated();

        $response = $this->getJson("/api/bccs/{$this->bcc->id}/leadership/eligible");
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($inMember->id, $ids);
        $this->assertCount(1, $ids);
        $this->assertEquals('FAM000005', $response->json('data.0.family_code'));
        $this->assertArrayHasKey('display_name', $response->json('data.0'));
    }

    public function test_member_history_and_audit_are_written(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
        ]);

        $this->postJson("/api/bccs/{$this->bcc->id}/members", [
            'family_ids' => [$family->id],
        ])->assertCreated();

        $members = $this->getJson("/api/bccs/{$this->bcc->id}/members");
        $members->assertOk();
        $membershipId = $members->json('data.0.id');
        $this->assertNotEmpty($membershipId);

        $this->deleteJson("/api/bccs/{$this->bcc->id}/members/{$membershipId}")->assertOk();

        $history = $this->getJson("/api/bccs/{$this->bcc->id}/member-history");
        $history->assertOk();
        $this->assertGreaterThanOrEqual(1, count($history->json('data')));
        $this->assertFalse((bool) $history->json('data.0.is_current'));

        $audit = $this->getJson("/api/bccs/{$this->bcc->id}/audit-logs");
        $audit->assertOk();
        $events = collect($audit->json('data'))->pluck('event')->all();
        $this->assertContains('membership.assigned', $events);
        $this->assertContains('membership.removed', $events);
    }

    public function test_family_pointer_sync_opens_membership_interval(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bcc_id' => null,
            'status' => 'active',
        ]);

        $family->bcc_id = $this->bcc->id;
        $family->save();

        app(BccFamilyMembershipService::class)
            ->syncFromFamilyPointer($family, null, (int) $this->tenant->id);

        $this->assertDatabaseHas('bcc_family_memberships', [
            'family_id' => $family->id,
            'bcc_id' => $this->bcc->id,
            'is_current' => true,
            'status' => 'active',
        ]);
    }
}
