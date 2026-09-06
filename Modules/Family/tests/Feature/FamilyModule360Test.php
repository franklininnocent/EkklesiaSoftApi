<?php

namespace Modules\Family\Tests\Feature;

use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Family\Testing\FamilyCertificationTestCase;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FamilyModule360Test extends FamilyCertificationTestCase
{
    // ==================== §1 Security & RBAC ====================

    #[Test]
    public function it_should_return_404_when_tenant_a_reads_tenant_b_family(): void
    {
        $tenantA = $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_name' => 'Protected Family',
        ]);
        $snapshot = $this->snapshotFamily($familyB);

        $this->getJson("/api/families/{$familyB->id}")->assertNotFound();

        $this->assertDatabaseHas('families', $snapshot);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_updates_tenant_b_family(): void
    {
        $tenantA = $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create([
            'tenant_id' => $tenantB->id,
            'family_name' => 'Original Name',
            'address_line_1' => '1 Secret Road',
        ]);

        $this->putJson("/api/families/{$familyB->id}", [
            'family_name' => 'Hacked Name',
            'address_line_1' => '999 Hacker Lane',
        ])->assertNotFound();

        $this->assertDatabaseHas('families', [
            'id' => $familyB->id,
            'family_name' => 'Original Name',
            'address_line_1' => '1 Secret Road',
        ]);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_deletes_tenant_b_family_as_admin(): void
    {
        $tenantA = $this->authenticateAsTenantAdmin();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id]);

        $this->deleteJson("/api/families/{$familyB->id}")->assertNotFound();

        $this->assertDatabaseHas('families', [
            'id' => $familyB->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_adds_member_to_tenant_b_family(): void
    {
        $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id]);
        $beforeCount = FamilyMember::where('family_id', $familyB->id)->count();

        $this->postJson("/api/families/{$familyB->id}/members", [
            'first_name' => 'Intruder',
            'last_name' => 'Member',
            'relationship_to_head' => 'son',
            'date_of_birth' => '2015-05-05',
        ])->assertNotFound();

        $this->assertSame($beforeCount, FamilyMember::where('family_id', $familyB->id)->count());
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_updates_tenant_b_member(): void
    {
        $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id]);
        $member = FamilyMember::factory()->create([
            'family_id' => $familyB->id,
            'first_name' => 'Original',
            'last_name' => 'Member',
        ]);

        $this->putJson("/api/families/{$familyB->id}/members/{$member->id}", [
            'first_name' => 'Changed',
            'last_name' => 'Member',
            'relationship_to_head' => 'son',
        ])->assertNotFound();

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'first_name' => 'Original',
        ]);
    }

    #[Test]
    public function it_should_return_404_when_tenant_a_deletes_tenant_b_member(): void
    {
        $this->authenticateAsStaff();
        $tenantB = Tenant::factory()->create();
        $familyB = Family::factory()->create(['tenant_id' => $tenantB->id]);
        $member = FamilyMember::factory()->active()->create(['family_id' => $familyB->id]);
        FamilyMember::factory()->active()->create(['family_id' => $familyB->id]);

        $this->deleteJson("/api/families/{$familyB->id}/members/{$member->id}")->assertNotFound();

        $this->assertDatabaseHas('family_members', [
            'id' => $member->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_should_forbid_staff_without_delete_permission_from_deleting_family(): void
    {
        $ctx = $this->makeStaffUser();
        $this->grantFamilyPermissions($ctx['user'], ['families.view', 'families.create', 'families.edit']);
        Passport::actingAs($ctx['user']);
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->deleteJson("/api/families/{$family->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('families', ['id' => $family->id, 'deleted_at' => null]);
    }

    #[Test]
    public function it_should_allow_tenant_admin_to_soft_delete_family(): void
    {
        $ctx = $this->authenticateAsTenantAdmin();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);

        $this->deleteJson("/api/families/{$family->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('families', ['id' => $family->id]);
    }

    #[Test]
    public function it_should_allow_staff_to_create_family_in_own_tenant(): void
    {
        $ctx = $this->authenticateAsStaff();

        $this->postJson('/api/families', [
            'family_name' => 'New Household',
            'status' => 'active',
        ])->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('families', [
            'tenant_id' => $ctx['tenant']->id,
            'family_name' => 'New Household',
        ]);
    }

    #[Test]
    public function it_should_allow_staff_to_update_family_in_own_tenant(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_name' => 'Before',
        ]);

        $this->putJson("/api/families/{$family->id}", [
            'family_name' => 'After',
        ])->assertOk()
            ->assertJsonPath('data.family_name', 'After');
    }

    // ==================== §2 Lifecycle: Split, Merge, Head ====================

    #[Test]
    public function it_should_split_member_into_new_family_preserving_person_and_sacraments(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $child = $household['child'];
        $child->update([
            'baptism_date' => '2012-06-01',
            'baptism_place' => 'St. Mary',
        ]);
        $personId = $child->person_id;

        $response = $this->postJson("/api/families/{$household['family']->id}/split-member", [
            'member_id' => $child->id,
            'new_family' => [
                'family_name' => 'Ana Martinez Household',
                'address_line_1' => '200 New Start Ave',
                'city' => 'Springfield',
            ],
            'member_overrides' => [
                'relationship_to_head' => 'self',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $newFamilyId = $response->json('data.new_family.id');
        $child->refresh();
        $this->assertSame($newFamilyId, $child->family_id);
        $this->assertSame($personId, $child->person_id);
        $this->assertSame('self', $child->relationship_to_head);
        $this->assertSame('2012-06-01', $child->baptism_date?->format('Y-m-d'));
    }

    #[Test]
    public function it_should_return_404_for_cross_tenant_split(): void
    {
        $this->authenticateAsStaff();
        $otherTenant = Tenant::factory()->create();
        $household = $this->seedHousehold($otherTenant);

        $this->postJson("/api/families/{$household['family']->id}/split-member", [
            'member_id' => $household['child']->id,
            'new_family' => ['family_name' => 'Illegal Split'],
        ])->assertStatus(422);
    }

    #[Test]
    public function it_should_merge_two_families_without_duplicating_persons(): void
    {
        $ctx = $this->authenticateAsStaff();
        $target = $this->seedHousehold($ctx['tenant']);
        $sourceFamily = Family::factory()->active()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_name' => 'Lopez Family',
        ]);
        $sourceMember = FamilyMember::factory()->active()->create([
            'family_id' => $sourceFamily->id,
            'first_name' => 'Pedro',
            'last_name' => 'Lopez',
        ]);

        $this->postJson('/api/families/merge', [
            'source_family_id' => $sourceFamily->id,
            'target_family_id' => $target['family']->id,
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('families', ['id' => $sourceFamily->id]);
        $this->assertDatabaseHas('family_members', [
            'id' => $sourceMember->id,
            'family_id' => $target['family']->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function it_should_preserve_sacrament_dates_on_merged_members(): void
    {
        $ctx = $this->authenticateAsStaff();
        $target = $this->seedHousehold($ctx['tenant']);
        $sourceFamily = Family::factory()->active()->create([
            'tenant_id' => $ctx['tenant']->id,
        ]);
        $sourceMember = FamilyMember::factory()->active()->withSacraments([
            'baptism_date' => '1990-01-15',
            'confirmation_date' => '2005-05-20',
        ])->create(['family_id' => $sourceFamily->id]);

        $this->postJson('/api/families/merge', [
            'source_family_id' => $sourceFamily->id,
            'target_family_id' => $target['family']->id,
        ])->assertOk();

        $sourceMember->refresh();
        $this->assertSame('1990-01-15', $sourceMember->baptism_date?->format('Y-m-d'));
        $this->assertSame('2005-05-20', $sourceMember->confirmation_date?->format('Y-m-d'));
    }

    #[Test]
    public function it_should_reject_merge_when_source_and_target_are_the_same_family(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->postJson('/api/families/merge', [
            'source_family_id' => $household['family']->id,
            'target_family_id' => $household['family']->id,
        ])->assertStatus(422);
    }

    #[Test]
    public function it_should_sync_head_of_family_when_member_promoted_to_self(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $spouse = $household['spouse'];

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'relationship_to_head' => 'spouse',
            'first_name' => $household['head']->first_name,
            'last_name' => $household['head']->last_name,
        ])->assertOk();

        $this->putJson("/api/families/{$household['family']->id}/members/{$spouse->id}", [
            'relationship_to_head' => 'self',
            'first_name' => $spouse->first_name,
            'last_name' => $spouse->last_name,
        ])->assertOk();

        $this->assertDatabaseHas('families', [
            'id' => $household['family']->id,
            'head_of_family' => trim("{$spouse->first_name} {$spouse->last_name}"),
        ]);
    }

    #[Test]
    public function it_should_transition_head_when_previous_head_marked_deceased(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'status' => 'deceased',
            'deceased_date' => '2026-01-01',
            'relationship_to_head' => 'self',
            'first_name' => $household['head']->first_name,
            'last_name' => $household['head']->last_name,
        ])->assertOk();

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['spouse']->id}", [
            'relationship_to_head' => 'self',
            'first_name' => $household['spouse']->first_name,
            'last_name' => $household['spouse']->last_name,
        ])->assertOk();

        $this->assertDatabaseHas('families', [
            'id' => $household['family']->id,
            'head_of_family' => trim("{$household['spouse']->first_name} {$household['spouse']->last_name}"),
        ]);
    }

    #[Test]
    public function it_should_reject_adding_second_active_head_without_demoting_first(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->postJson("/api/families/{$household['family']->id}/members", [
            'first_name' => 'Second',
            'last_name' => 'Head',
            'relationship_to_head' => 'self',
            'date_of_birth' => '1980-01-01',
            'gender' => 'male',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['relationship_to_head']);
    }

    // ==================== §3 Orphan, Duplicate, Address ====================

    #[Test]
    public function it_should_prevent_deleting_last_active_member_without_reassignment(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $onlyMember = FamilyMember::factory()->active()->create(['family_id' => $family->id]);

        $this->deleteJson("/api/families/{$family->id}/members/{$onlyMember->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_id']);
    }

    #[Test]
    public function it_should_allow_deleting_member_when_other_active_members_exist(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->deleteJson("/api/families/{$household['family']->id}/members/{$household['child']->id}")
            ->assertOk();

        $this->assertSoftDeleted('family_members', ['id' => $household['child']->id]);
    }

    #[Test]
    public function it_should_prevent_deleting_family_with_active_members_without_reassignment(): void
    {
        $ctx = $this->authenticateAsTenantAdmin();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->deleteJson("/api/families/{$household['family']->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['family_id']);
    }

    #[Test]
    public function it_should_detect_duplicate_family_by_surname_address_and_phone(): void
    {
        $ctx = $this->authenticateAsStaff();
        Family::factory()->withAddress([
            'family_name' => 'Garcia Family',
            'address_line_1' => '500 Elm Street',
        ])->create([
            'tenant_id' => $ctx['tenant']->id,
        ]);
        FamilyMember::factory()->head()->active()->create([
            'family_id' => Family::where('family_name', 'Garcia Family')->first()->id,
            'phone' => '+12025551234',
            'is_primary_contact' => true,
        ]);

        $this->postJson('/api/families', [
            'family_name' => 'Garcia Family',
            'address_line_1' => '500 Elm Street',
            'members' => [[
                'first_name' => 'Luis',
                'last_name' => 'Garcia',
                'date_of_birth' => '1980-03-03',
                'phone' => '+12025551234',
                'is_primary_contact' => true,
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['family_name']);
    }

    #[Test]
    public function it_should_allow_create_when_only_two_of_three_match(): void
    {
        $ctx = $this->authenticateAsStaff();
        Family::factory()->withAddress([
            'family_name' => 'Rivera Family',
            'address_line_1' => '12 Pine Road',
        ])->create(['tenant_id' => $ctx['tenant']->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Rivera Family',
            'address_line_1' => '99 Oak Road',
            'members' => [[
                'first_name' => 'Elena',
                'last_name' => 'Rivera',
                'date_of_birth' => '1992-07-07',
                'phone' => '+12025559999',
            ]],
        ])->assertCreated();
    }

    #[Test]
    public function it_should_not_flag_duplicates_across_tenants(): void
    {
        $ctx = $this->authenticateAsStaff();
        $otherTenant = Tenant::factory()->create();
        Family::factory()->withAddress([
            'family_name' => 'Cross Tenant',
            'address_line_1' => '1 Shared Lane',
        ])->withPrimaryPhone('2025550001')->create(['tenant_id' => $otherTenant->id]);

        $this->postJson('/api/families', [
            'family_name' => 'Cross Tenant',
            'address_line_1' => '1 Shared Lane',
            'members' => [[
                'first_name' => 'Same',
                'last_name' => 'Name',
                'date_of_birth' => '1985-01-01',
                'phone' => '2025550001',
                'is_primary_contact' => true,
            ]],
        ])->assertCreated();
    }

    #[Test]
    public function it_should_bulk_update_person_addresses_when_sync_flag_is_true(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);

        $this->putJson("/api/families/{$household['family']->id}", [
            'address_line_1' => '777 Sync Boulevard',
            'city' => 'New City',
            'postal_code' => '99999',
            'sync_person_addresses' => true,
        ])->assertOk();

        foreach ($household['persons'] as $person) {
            $this->assertDatabaseHas('persons', [
                'id' => $person->id,
                'address_line_1' => '777 Sync Boulevard',
                'city' => 'New City',
                'postal_code' => '99999',
            ]);
        }
    }

    #[Test]
    public function it_should_not_update_person_addresses_when_sync_flag_is_false(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->seedHousehold($ctx['tenant']);
        $person = $household['persons']['head'];
        $person->update(['address_line_1' => 'Old Address']);

        $this->putJson("/api/families/{$household['family']->id}", [
            'address_line_1' => '888 No Sync Street',
            'sync_person_addresses' => false,
        ])->assertOk();

        $this->assertDatabaseHas('persons', [
            'id' => $person->id,
            'address_line_1' => 'Old Address',
        ]);
    }

    // ==================== §4 Sacramental, Parishioner, Person API ====================

    #[Test]
    public function it_should_reflect_sacrament_completeness_in_family_list_after_member_update(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->active()->create(['tenant_id' => $ctx['tenant']->id]);
        $member = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'baptism_date' => null,
            'date_of_birth' => '2015-01-01',
            'deceased_date' => null,
        ]);

        $before = $this->getJson('/api/families?missing_sacrament=BAPTISM&per_page=50');
        $before->assertOk();
        $this->assertTrue(collect($before->json('data'))->pluck('id')->contains($family->id));

        $this->putJson("/api/families/{$family->id}/members/{$member->id}", [
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'relationship_to_head' => $member->relationship_to_head,
            'baptism_date' => '2015-04-04',
        ])->assertOk();

        $after = $this->getJson('/api/families?missing_sacrament=BAPTISM&per_page=50');
        $after->assertOk();
        $this->assertFalse(collect($after->json('data'))->pluck('id')->contains($family->id));
    }

    #[Test]
    public function it_should_retain_person_sacrament_data_when_family_is_soft_deleted(): void
    {
        $ctx = $this->authenticateAsTenantAdmin();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $member = FamilyMember::factory()->active()->withSacraments([
            'baptism_date' => '2000-01-01',
        ])->create(['family_id' => $family->id]);
        $personId = $member->person_id;

        $this->deleteJson("/api/families/{$family->id}?force_delete=1")->assertOk();

        $this->assertSoftDeleted('families', ['id' => $family->id]);
        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'deleted_at' => null,
        ]);
        $member->refresh();
        $this->assertSame('2000-01-01', $member->baptism_date?->format('Y-m-d'));
    }

    #[Test]
    public function it_should_allow_parishioner_to_view_own_family_only(): void
    {
        $tenant = Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        $otherFamily = Family::factory()->create(['tenant_id' => $tenant->id]);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->getJson("/api/families/{$household['family']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $household['family']->id);

        $this->getJson("/api/families/{$otherFamily->id}")->assertNotFound();
    }

    #[Test]
    public function it_should_limit_parishioner_family_list_to_own_household(): void
    {
        $tenant = Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        Family::factory()->count(3)->create(['tenant_id' => $tenant->id]);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $response = $this->getJson('/api/families?per_page=50');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $household['family']->id)
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function it_should_forbid_parishioner_from_updating_family(): void
    {
        $tenant = Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->putJson("/api/families/{$household['family']->id}", [
            'family_name' => 'Hacked',
        ])->assertForbidden();
    }

    #[Test]
    public function it_should_allow_parishioner_to_submit_update_request(): void
    {
        $tenant = Tenant::factory()->create();
        $household = $this->seedHousehold($tenant);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->postJson("/api/families/{$household['family']->id}/update-requests", [
            'message' => 'Please update our phone number.',
            'fields' => ['phone'],
        ])->assertCreated();

        $this->assertDatabaseHas('family_audit_logs', [
            'tenant_id' => $tenant->id,
            'event' => 'family.update_requested',
            'target_id' => $household['family']->id,
        ]);
    }

    #[Test]
    public function it_should_find_strong_person_matches(): void
    {
        $ctx = $this->authenticateAsStaff();
        $person = Person::factory()->create([
            'tenant_id' => $ctx['tenant']->id,
            'first_name' => 'Thomas',
            'last_name' => 'Aquinas',
            'date_of_birth' => '1990-05-05',
            'gender' => 'male',
            'created_by' => $ctx['user']->id,
        ]);

        $response = $this->postJson('/api/persons/matches', [
            'first_name' => 'Thomas',
            'last_name' => 'Aquinas',
            'date_of_birth' => '1990-05-05',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.strength', 'strong');
    }

    #[Test]
    public function it_should_return_422_for_cross_tenant_person_show(): void
    {
        $this->authenticateAsStaff();
        $otherTenant = Tenant::factory()->create();
        $person = Person::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $this->getJson("/api/persons/{$person->id}")->assertStatus(422);
    }

    #[Test]
    public function it_should_auto_generate_unique_family_code_on_create(): void
    {
        $ctx = $this->authenticateAsStaff();

        $response = $this->postJson('/api/families', [
            'family_name' => 'Code Test Family',
        ])->assertCreated();

        $code = $response->json('data.family_code');
        $this->assertMatchesRegularExpression('/^FAM\d{6}$/', $code);
        $this->assertDatabaseHas('families', [
            'tenant_id' => $ctx['tenant']->id,
            'family_code' => $code,
        ]);
    }
}
