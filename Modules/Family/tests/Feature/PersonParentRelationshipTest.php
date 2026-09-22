<?php

namespace Modules\Family\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonParentRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        Passport::actingAs($this->user);
    }

    #[Test]
    public function it_persists_linked_father_and_typed_mother_on_add_member(): void
    {
        $fatherPerson = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Joseph',
            'last_name' => 'Peter',
            'gender' => 'male',
            'created_by' => $this->user->id,
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/families/{$family->id}/members", [
            'first_name' => 'Anna',
            'last_name' => 'Peter',
            'date_of_birth' => '2010-05-01',
            'relationship_to_head' => 'daughter',
            'gender' => 'female',
            'father_person_id' => $fatherPerson->id,
            'mother_name' => 'Mary Peter',
        ]);

        $response->assertCreated();

        $member = FamilyMember::query()->where('family_id', $family->id)->first();
        $this->assertNotNull($member);
        $person = Person::query()->find($member->person_id);
        $this->assertNotNull($person);
        $this->assertSame($fatherPerson->id, $person->father_person_id);
        $this->assertSame('Joseph Peter', $person->father_name);
        $this->assertNull($person->mother_person_id);
        $this->assertSame('Mary Peter', $person->mother_name);
    }

    #[Test]
    public function it_rejects_self_reference_as_father(): void
    {
        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Paul',
            'last_name' => 'Smith',
            'relationship_to_head' => 'self',
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson("/api/families/{$family->id}/members/{$member->id}", [
            'first_name' => 'Paul',
            'last_name' => 'Smith',
            'father_person_id' => $member->person_id,
            'father_name' => 'Paul Smith',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['father_person_id']);
    }

    #[Test]
    public function omitting_parent_fields_does_not_clear_existing_parents(): void
    {
        $fatherPerson = Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Joseph',
            'last_name' => 'Peter',
            'gender' => 'male',
            'created_by' => $this->user->id,
        ]);

        $family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $member = FamilyMember::factory()->create([
            'family_id' => $family->id,
            'first_name' => 'Anna',
            'last_name' => 'Peter',
            'relationship_to_head' => 'daughter',
            'created_by' => $this->user->id,
        ]);

        $person = Person::query()->find($member->person_id);
        $person->update([
            'father_person_id' => $fatherPerson->id,
            'father_name' => 'Joseph Peter',
            'mother_name' => 'Mary Peter',
        ]);

        $response = $this->putJson("/api/families/{$family->id}/members/{$member->id}", [
            'first_name' => 'Anna',
            'last_name' => 'Peter',
            'phone' => '+919876543210',
        ]);

        $response->assertOk();

        $person->refresh();
        $this->assertSame($fatherPerson->id, $person->father_person_id);
        $this->assertSame('Joseph Peter', $person->father_name);
        $this->assertSame('Mary Peter', $person->mother_name);
    }
}
