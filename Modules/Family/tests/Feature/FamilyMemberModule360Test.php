<?php

namespace Modules\Family\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Modules\Family\Events\FamilyMemberStatusChanged;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Family\Testing\FamilyCertificationTestCase;
use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FamilyMemberModule360Test extends FamilyCertificationTestCase
{
    // ==================== §1 Multi-tenant isolation & search leakage ====================

    #[Test]
    public function it_should_exclude_tenant_b_members_from_parish_directory(): void
    {
        $ctx = $this->authenticateAsStaff();
        $householdA = $this->seedHousehold($ctx['tenant']);
        $tenantB = Tenant::factory()->create();
        $householdB = $this->seedHousehold($tenantB, [
            'family_name' => 'Other Parish Family',
            'head_first_name' => 'Hidden',
            'head_last_name' => 'Neighbor',
        ]);

        $response = $this->getJson('/api/members?per_page=100');

        $response->assertOk()->assertJsonPath('success', true);
        $ids = $this->memberIds($response);
        $this->assertContains($householdA['head']->id, $ids);
        $this->assertContains($householdA['spouse']->id, $ids);
        $this->assertContains($householdA['child']->id, $ids);
        $this->assertNotContains($householdB['head']->id, $ids);
        $this->assertNotContains($householdB['spouse']->id, $ids);
        $this->assertNotContains($householdB['child']->id, $ids);
        $this->assertSame(3, $response->json('total'));
    }

    #[Test]
    public function it_should_scope_mixed_case_member_search_to_active_tenant(): void
    {
        $ctx = $this->authenticateAsStaff();
        $householdA = $this->seedHousehold($ctx['tenant']);
        $visible = FamilyMember::factory()->active()->create([
            'family_id' => $householdA['family']->id,
            'first_name' => 'Zephyr',
            'middle_name' => null,
            'last_name' => 'Quintana',
            'relationship_to_head' => 'cousin',
        ]);

        $tenantB = Tenant::factory()->create();
        $householdB = $this->seedHousehold($tenantB);
        FamilyMember::factory()->active()->create([
            'family_id' => $householdB['family']->id,
            'first_name' => 'Zephyr',
            'middle_name' => null,
            'last_name' => 'Quintana',
            'relationship_to_head' => 'cousin',
        ]);

        $byFirst = $this->getJson('/api/members?search=zEpHyR&per_page=50');
        $byFirst->assertOk();
        $this->assertSame([$visible->id], $this->memberIds($byFirst));

        $byFullName = $this->getJson('/api/members?search=Zephyr Quintana&per_page=50');
        $byFullName->assertOk()->assertJsonPath('success', true);
        $this->assertNotContains(
            FamilyMember::query()->where('family_id', $householdB['family']->id)->where('first_name', 'Zephyr')->value('id'),
            $this->memberIds($byFullName)
        );
    }

    #[Test]
    public function it_should_scope_status_and_head_filters_to_active_tenant(): void
    {
        $ctx = $this->authenticateAsStaff();
        $householdA = $this->seedHousehold($ctx['tenant']);
        $inactiveA = FamilyMember::factory()->inactive()->create([
            'family_id' => $householdA['family']->id,
            'first_name' => 'Inactive',
            'last_name' => 'Alpha',
            'relationship_to_head' => 'cousin',
        ]);

        $tenantB = Tenant::factory()->create();
        $householdB = $this->seedHousehold($tenantB);
        FamilyMember::factory()->inactive()->create([
            'family_id' => $householdB['family']->id,
            'first_name' => 'Inactive',
            'last_name' => 'Bravo',
            'relationship_to_head' => 'cousin',
        ]);

        $inactive = $this->getJson('/api/members?status=inactive&per_page=50');
        $inactive->assertOk();
        $this->assertSame([$inactiveA->id], $this->memberIds($inactive));

        $heads = $this->getJson('/api/members?is_head=true&per_page=50');
        $heads->assertOk();
        $headIds = $this->memberIds($heads);
        $this->assertContains($householdA['head']->id, $headIds);
        $this->assertNotContains($householdB['head']->id, $headIds);
    }

    #[Test]
    public function it_should_return_404_when_listing_tenant_b_family_members(): void
    {
        $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $householdB = $this->seedHousehold($tenantB);
        $snapshot = $this->snapshotMember($householdB['head']);

        $this->getJson("/api/families/{$householdB['family']->id}/members")->assertNotFound();

        $this->assertDatabaseHas('family_members', $snapshot);
    }

    #[Test]
    public function it_should_not_leak_tenant_b_persons_in_search_or_matches(): void
    {
        $ctx = $this->authenticateAsStaff();
        $dob = now()->subYears(33)->toDateString();
        $local = Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Zephyr',
            'middle_name' => null,
            'last_name' => 'Quintana',
            'date_of_birth' => $dob,
            'gender' => 'female',
            'created_by' => $ctx['user']->id,
        ]);
        $otherTenant = Tenant::factory()->create();
        Person::factory()->create([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Zephyr',
            'middle_name' => null,
            'last_name' => 'Quintana',
            'date_of_birth' => $dob,
            'gender' => 'female',
        ]);

        $search = $this->getJson('/api/persons?search=zEpHyR&per_page=20');
        $search->assertOk();
        $searchIds = collect($search->json('data'))->pluck('id');
        $this->assertTrue($searchIds->contains($local->id));
        $this->assertCount(1, $searchIds);

        $fullNameSearch = $this->getJson('/api/persons?search=Zephyr Quintana&per_page=20');
        $fullNameSearch->assertOk()->assertJsonPath('success', true);

        $matches = $this->postJson('/api/persons/matches', [
            'first_name' => 'Zephyr',
            'last_name' => 'Quintana',
            'date_of_birth' => $dob,
        ]);
        $matches->assertOk();
        $this->assertSame([$local->id], collect($matches->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function it_should_forbid_parishioner_from_parish_directory_and_member_mutations(): void
    {
        $tenant = Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        $otherFamily = Family::factory()->create(['tenant_id' => $tenant->id]);
        FamilyMember::factory()->head()->active()->create(['family_id' => $otherFamily->id]);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->getJson('/api/members?per_page=50')->assertForbidden();

        $this->getJson("/api/families/{$household['family']->id}/members")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->getJson("/api/families/{$otherFamily->id}/members")->assertNotFound();

        $this->postJson("/api/families/{$household['family']->id}/members", [
            'first_name' => 'Intruder',
            'last_name' => 'Child',
            'relationship_to_head' => 'son',
            'date_of_birth' => now()->subYears(8)->toDateString(),
        ])->assertForbidden();

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['child']->id}", [
            'first_name' => 'Hacked',
            'last_name' => $household['child']->last_name,
            'relationship_to_head' => $household['child']->relationship_to_head,
        ])->assertForbidden();

        $this->deleteJson("/api/families/{$household['family']->id}/members/{$household['child']->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('family_members', [
            'id' => $household['child']->id,
            'first_name' => $household['child']->first_name,
            'deleted_at' => null,
        ]);
    }

    // ==================== §2 Lifecycle & profile ====================

    #[Test]
    public function it_should_create_member_and_canonical_person(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $dob = now()->subYears(30)->toDateString();

        $response = $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'Elena',
            'middle_name' => 'Rosa',
            'last_name' => 'Vega',
            'date_of_birth' => $dob,
            'gender' => 'female',
            'marital_status' => 'single',
            'phone' => '+12025550111',
            'email' => 'elena.vega@example.com',
            'relationship_to_head' => 'self',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.first_name', 'Elena')
            ->assertJsonPath('data.last_name', 'Vega')
            ->assertJsonPath('data.age', Carbon::parse($dob)->age);

        $personId = $response->json('data.person_id');
        $this->assertNotEmpty($personId);
        $this->assertDatabaseHas('family_members', [
            'id' => $response->json('data.id'),
            'family_id' => $family->id,
            'first_name' => 'Elena',
            'last_name' => 'Vega',
            'email' => 'elena.vega@example.com',
        ]);
        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Elena',
            'last_name' => 'Vega',
        ]);
    }

    #[Test]
    public function it_should_return_422_for_invalid_member_payloads(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'NoLast',
            'relationship_to_head' => 'son',
        ])->assertStatus(422)->assertJsonValidationErrors(['last_name', 'date_of_birth']);

        $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'Future',
            'last_name' => 'Child',
            'relationship_to_head' => 'son',
            'date_of_birth' => now()->addDay()->toDateString(),
            'gender' => 'unknown',
            'status' => 'moved_out',
        ])->assertStatus(422)->assertJsonValidationErrors(['date_of_birth', 'gender', 'status']);

        $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'Late',
            'last_name' => 'Parishioner',
            'relationship_to_head' => 'uncle',
            'date_of_birth' => now()->subYears(80)->toDateString(),
            'status' => 'deceased',
        ])->assertStatus(422)->assertJsonValidationErrors(['deceased_date']);
    }

    #[Test]
    public function it_should_transition_member_to_inactive(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $member = $household['spouse'];

        $this->putJson("/api/families/{$household['family']->id}/members/{$member->id}", [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'relationship_to_head' => $member->relationship_to_head,
            'status' => 'inactive',
        ])->assertOk();

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'status' => 'inactive',
        ]);
    }

    #[Test]
    public function it_should_transition_member_to_deceased_and_dispatch_census_event(): void
    {
        Event::fake([FamilyMemberStatusChanged::class]);
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $member = $household['spouse'];
        $deceasedDate = now()->subDay()->toDateString();

        $this->putJson("/api/families/{$household['family']->id}/members/{$member->id}", [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'relationship_to_head' => $member->relationship_to_head,
            'status' => 'deceased',
            'deceased_date' => $deceasedDate,
        ])->assertOk();

        $member->refresh();
        $this->assertSame('deceased', $member->status);
        $this->assertSame($deceasedDate, $member->deceased_date?->toDateString());

        Event::assertDispatched(FamilyMemberStatusChanged::class, function (FamilyMemberStatusChanged $event) use ($ctx, $member, $deceasedDate) {
            return $event->familyMemberId === $member->id
                && $event->previousStatus === 'active'
                && $event->newStatus === 'deceased'
                && $event->effectiveDate === $deceasedDate
                && $event->tenantId === (int) $ctx['tenant']->id;
        });
    }

    #[Test]
    public function it_should_transition_member_to_migrated_as_transferred_census_event(): void
    {
        Event::fake([FamilyMemberStatusChanged::class]);
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $member = $household['spouse'];

        $this->putJson("/api/families/{$household['family']->id}/members/{$member->id}", [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'relationship_to_head' => $member->relationship_to_head,
            'status' => 'migrated',
        ])->assertOk();

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'status' => 'migrated',
        ]);

        Event::assertDispatched(FamilyMemberStatusChanged::class, function (FamilyMemberStatusChanged $event) use ($member) {
            return $event->familyMemberId === $member->id
                && $event->previousStatus === 'active'
                && $event->newStatus === 'transferred';
        });
    }

    #[Test]
    public function it_should_filter_members_by_female_unmarried_over_18_progression(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $eligible = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(25)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);
        $tooYoung = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'gender' => 'female',
            'marital_status' => 'single',
            'date_of_birth' => now()->subYears(16)->toDateString(),
            'marriage_date' => null,
            'deceased_date' => null,
        ]);

        $response = $this->getJson('/api/members?progression=female_unmarried_over_18&per_page=50');
        $response->assertOk();
        $ids = $this->memberIds($response);
        $this->assertContains($eligible->id, $ids);
        $this->assertNotContains($tooYoung->id, $ids);
    }

    // ==================== §3 Household linking ====================

    #[Test]
    public function it_should_link_unaffiliated_person_to_family(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $dob = now()->subYears(22)->toDateString();
        $person = Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Unaffiliated',
            'last_name' => 'Parishioner',
            'date_of_birth' => $dob,
            'gender' => 'female',
            'created_by' => $ctx['user']->id,
        ]);

        $response = $this->postJson("/api/families/{$household['family']->id}/members", [
            'person_id' => $person->id,
            'relationship_to_head' => 'cousin',
            'date_of_birth' => $dob,
        ]);

        $response->assertCreated()->assertJsonPath('data.person_id', $person->id);
        $this->assertDatabaseHas('family_members', [
            'id' => $response->json('data.id'),
            'family_id' => $household['family']->id,
            'person_id' => $person->id,
            'first_name' => 'Unaffiliated',
            'last_name' => 'Parishioner',
        ]);
        $this->assertSame(1, FamilyMember::query()->where('person_id', $person->id)->whereNull('deleted_at')->count());
    }

    #[Test]
    public function it_should_reject_linking_person_who_already_belongs_to_a_family(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $otherFamily = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson("/api/families/{$otherFamily->id}/members", [
            'person_id' => $household['persons']['head']->id,
            'relationship_to_head' => 'cousin',
            'date_of_birth' => now()->subYears(40)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['person_id']);
    }

    #[Test]
    public function it_should_retain_person_when_member_is_soft_deleted(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $child = $household['child'];
        $personId = $child->person_id;

        $this->deleteJson("/api/families/{$household['family']->id}/members/{$child->id}")
            ->assertOk();

        $this->assertSoftDeleted('family_members', ['id' => $child->id]);
        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_should_not_sync_address_to_inactive_member_person(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $child = $household['child'];
        $child->update(['status' => 'inactive']);
        $household['persons']['child']->update(['address_line_1' => 'Kept Custom Lane']);
        $household['persons']['head']->update(['address_line_1' => 'Old Head Lane']);

        $this->putJson("/api/families/{$household['family']->id}", [
            'address_line_1' => '777 Sync Boulevard',
            'city' => 'New City',
            'postal_code' => '99999',
            'sync_person_addresses' => true,
        ])->assertOk();

        $this->assertDatabaseHas('persons', [
            'id' => $household['persons']['child']->id,
            'address_line_1' => 'Kept Custom Lane',
        ]);
        $this->assertDatabaseHas('persons', [
            'id' => $household['persons']['head']->id,
            'address_line_1' => '777 Sync Boulevard',
            'city' => 'New City',
            'postal_code' => '99999',
        ]);
    }

    // ==================== §4 Canonical / historical integrity ====================

    #[Test]
    public function it_should_not_rewrite_sacrament_recipient_name_when_surname_is_corrected(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $member = $household['child'];
        $frozenName = 'Ana Martinez';
        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $household['family']->id,
            'person_id' => $member->person_id,
            'recipient_name' => $frozenName,
            'created_by' => $ctx['user']->id,
            'updated_by' => $ctx['user']->id,
        ]);

        $this->putJson("/api/families/{$household['family']->id}/members/{$member->id}", [
            'first_name' => $member->first_name,
            'last_name' => 'Martinez-Reyes',
            'relationship_to_head' => $member->relationship_to_head,
        ])->assertOk();

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'last_name' => 'Martinez-Reyes',
        ]);
        $this->assertDatabaseHas('persons', [
            'id' => $member->person_id,
            'last_name' => 'Martinez-Reyes',
        ]);
        $this->assertDatabaseHas('sacraments', [
            'id' => $sacrament->id,
            'recipient_name' => $frozenName,
            'person_id' => $member->person_id,
        ]);
    }

    #[Test]
    public function it_should_retain_sacrament_and_person_when_member_is_archived(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $member = $household['child'];
        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $household['family']->id,
            'person_id' => $member->person_id,
            'recipient_name' => $member->full_name_display,
            'created_by' => $ctx['user']->id,
            'updated_by' => $ctx['user']->id,
        ]);

        $this->deleteJson("/api/families/{$household['family']->id}/members/{$member->id}")
            ->assertOk();

        $this->assertSoftDeleted('family_members', ['id' => $member->id]);
        $this->assertDatabaseHas('persons', [
            'id' => $member->person_id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('sacraments', [
            'id' => $sacrament->id,
            'person_id' => $member->person_id,
            'deleted_at' => null,
        ]);
    }

    // ==================== §5 Duplicate matches & dialect ====================

    #[Test]
    public function it_should_return_strong_person_match_on_name_and_date_of_birth(): void
    {
        $ctx = $this->authenticateAsStaff();
        $dob = now()->subYears(41)->toDateString();
        $person = Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => $dob,
            'gender' => 'female',
            'created_by' => $ctx['user']->id,
        ]);

        $this->postJson('/api/persons/matches', [
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => $dob,
        ])->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.strength', 'strong');
    }

    #[Test]
    public function it_should_return_medium_person_match_on_name_and_phone_or_email(): void
    {
        $ctx = $this->authenticateAsStaff();
        $person = Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => now()->subYears(41)->toDateString(),
            'gender' => 'female',
            'phone' => '2025550199',
            'email' => 'lucia.santos@example.com',
            'created_by' => $ctx['user']->id,
        ]);

        $this->postJson('/api/persons/matches', [
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'gender' => 'female',
            'phone' => '2025550199',
        ])->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.strength', 'medium');

        $this->postJson('/api/persons/matches', [
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'gender' => 'female',
            'email' => 'lucia.santos@example.com',
        ])->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.strength', 'medium');
    }

    #[Test]
    public function it_should_not_match_when_only_the_name_is_the_same(): void
    {
        $ctx = $this->authenticateAsStaff();
        Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => now()->subYears(41)->toDateString(),
            'gender' => 'female',
            'phone' => null,
            'email' => null,
            'created_by' => $ctx['user']->id,
        ]);

        $this->postJson('/api/persons/matches', [
            'first_name' => 'Lucia',
            'last_name' => 'Santos',
            'date_of_birth' => now()->subYears(20)->toDateString(),
            'gender' => 'male',
        ])->assertOk()->assertJsonPath('data', []);
    }

    /**
     * @return list<string>
     */
    private function memberIds($response): array
    {
        return collect($response->json('data'))->pluck('id')->values()->all();
    }
}
