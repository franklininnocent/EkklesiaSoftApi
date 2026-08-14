<?php

namespace Modules\Sacraments\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\app\Services\PersonBackfillService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentPersonRecipientTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected SacramentType $baptismType;

    protected SacramentType $eucharistType;

    protected Family $family;

    protected FamilyMember $member;

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
        $this->grantPermissions($role);
        $this->otherTenant = Tenant::factory()->create();

        $this->baptismType = SacramentType::factory()->create([
            'name' => 'Baptism',
            'code' => 'BAPTISM',
            'active' => true,
            'requires_minister' => true,
        ]);
        $this->eucharistType = SacramentType::factory()->create([
            'name' => 'Eucharist',
            'code' => 'EUCHARIST',
            'active' => true,
            'requires_minister' => true,
        ]);

        $this->family = Family::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->member = FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'first_name' => 'John',
            'middle_name' => null,
            'last_name' => 'Thomas',
            'date_of_birth' => '2015-04-10',
            'gender' => 'male',
        ]);

        Passport::actingAs($this->user);
    }

    private function grantPermissions(Role $role): void
    {
        $ids = [];
        foreach (['sacraments.view', 'sacraments.create', 'sacraments.edit', 'families.view', 'families.create'] as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test',
                    'module' => str_starts_with($name, 'families') ? 'Family' : 'Sacraments',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => str_starts_with($name, 'families') ? 'family' : 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
    }

    /** @return list<array<string, mixed>> */
    private function minister(): array
    {
        return [[
            'role' => 'minister',
            'source' => 'external',
            'external_full_name' => 'Fr. Thomas',
            'external_title' => 'Fr.',
            'external_minister_role' => 'priest',
        ]];
    }

    /** @return array<string, string> */
    private function identityFields(array $overrides = []): array
    {
        return array_merge([
            'place_administered' => 'St. Mary',
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'father_name' => 'Joseph Thomas',
            'mother_name' => 'Mary Thomas',
        ], $overrides);
    }

    #[Test]
    public function existing_family_reuses_member_person(): void
    {
        $before = Person::query()->count();

        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'place_administered' => 'St. Mary',
            'family_association' => 'existing',
            'family_id' => $this->family->id,
            'family_member_id' => $this->member->id,
            'recipient_birth_date' => '2015-04-10',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'participants' => array_merge([
                ['role' => 'father', 'source' => 'external', 'external_full_name' => 'Joseph Thomas'],
                ['role' => 'mother', 'source' => 'external', 'external_full_name' => 'Mary Thomas'],
            ], $this->minister()),
        ]);

        $response->assertCreated();
        $this->assertSame($this->member->person_id, $response->json('data.person_id'));
        $this->assertSame($before, Person::query()->count());
        $this->assertDatabaseHas('sacrament_participants', [
            'sacrament_id' => $response->json('data.id'),
            'role' => 'recipient',
            'person_id' => $this->member->person_id,
            'family_member_id' => $this->member->id,
        ]);
    }

    #[Test]
    public function no_family_creates_person_without_family_or_member(): void
    {
        $families = Family::query()->count();
        $members = FamilyMember::query()->count();

        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'place_administered' => 'St. Mary',
            'family_association' => 'none',
            'recipient_birth_date' => '2018-01-01',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'female',
            'person' => [
                'first_name' => 'Anna',
                'last_name' => 'Unaffiliated',
                'date_of_birth' => '2018-01-01',
                'place_of_birth' => 'Parish City',
                'gender' => 'female',
                'father_name' => 'Paul Unaffiliated',
                'mother_name' => 'Ruth Unaffiliated',
            ],
            'participants' => array_merge([
                ['role' => 'father', 'source' => 'external', 'external_full_name' => 'Paul Unaffiliated'],
                ['role' => 'mother', 'source' => 'external', 'external_full_name' => 'Ruth Unaffiliated'],
            ], $this->minister()),
        ]);

        $response->assertCreated();
        $personId = $response->json('data.person_id');
        $this->assertNotEmpty($personId);
        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Anna',
            'last_name' => 'Unaffiliated',
        ]);
        $this->assertSame($families, Family::query()->count());
        $this->assertSame($members, FamilyMember::query()->count());
        $this->assertDatabaseHas('sacrament_participants', [
            'sacrament_id' => $response->json('data.id'),
            'role' => 'recipient',
            'source' => 'person',
            'person_id' => $personId,
            'family_member_id' => null,
        ]);
    }

    #[Test]
    public function new_family_creates_family_person_member_and_sacrament_atomically(): void
    {
        $response = $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'place_administered' => 'St. Mary',
            'family_association' => 'new',
            'recipient_birth_date' => '2019-05-05',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'family' => [
                'family_name' => 'Newborn Family',
                'address_line_1' => '1 Church Rd',
            ],
            'person' => [
                'first_name' => 'Luke',
                'last_name' => 'Newborn',
                'date_of_birth' => '2019-05-05',
                'place_of_birth' => 'Parish City',
                'gender' => 'male',
            ],
            'relationship_to_head' => 'son',
            'participants' => array_merge([
                ['role' => 'father', 'source' => 'external', 'external_full_name' => 'Mark Newborn'],
                ['role' => 'mother', 'source' => 'external', 'external_full_name' => 'Eve Newborn'],
            ], $this->minister()),
        ]);

        $response->assertCreated();
        $personId = $response->json('data.person_id');
        $familyId = $response->json('data.family_id');
        $this->assertNotEmpty($personId);
        $this->assertNotEmpty($familyId);
        $this->assertDatabaseHas('families', ['id' => $familyId, 'family_name' => 'Newborn Family']);
        $this->assertDatabaseHas('family_members', [
            'family_id' => $familyId,
            'person_id' => $personId,
            'first_name' => 'Luke',
        ]);
    }

    #[Test]
    public function later_family_link_keeps_sacrament_on_person(): void
    {
        $create = $this->postJson('/api/sacraments', array_merge($this->identityFields([
            'recipient_birth_date' => '2017-02-02',
        ]), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'family_association' => 'none',
            'person' => [
                'first_name' => 'Peter',
                'last_name' => 'Later',
                'date_of_birth' => '2017-02-02',
                'place_of_birth' => 'Parish City',
                'gender' => 'male',
                'father_name' => 'Joseph Thomas',
                'mother_name' => 'Mary Thomas',
            ],
            'participants' => $this->minister(),
        ]));
        $create->assertCreated();
        $personId = $create->json('data.person_id');
        $sacramentId = $create->json('data.id');

        $link = $this->postJson('/api/families/'.$this->family->id.'/members', [
            'person_id' => $personId,
            'relationship_to_head' => 'son',
        ]);
        $link->assertCreated();

        $this->assertSame($personId, Sacrament::query()->find($sacramentId)->person_id);
        $this->assertDatabaseHas('family_members', [
            'person_id' => $personId,
            'family_id' => $this->family->id,
        ]);
    }

    #[Test]
    public function eucharist_reuses_same_person_after_baptism(): void
    {
        $baptism = $this->postJson('/api/sacraments', array_merge($this->identityFields(), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2020-01-01',
            'family_association' => 'existing',
            'family_id' => $this->family->id,
            'family_member_id' => $this->member->id,
            'participants' => $this->minister(),
        ]));
        $baptism->assertCreated();
        $personId = $baptism->json('data.person_id');
        $people = Person::query()->count();

        $eucharist = $this->postJson('/api/sacraments', array_merge($this->identityFields(), [
            'sacrament_type_id' => $this->eucharistType->id,
            'date_administered' => '2026-08-01',
            'baptism_date' => '2020-01-01',
            'family_association' => 'existing',
            'family_id' => $this->family->id,
            'family_member_id' => $this->member->id,
            'event_subtype' => 'FIRST_COMMUNION',
            'participants' => $this->minister(),
        ]));

        $eucharist->assertCreated();
        $this->assertSame($personId, $eucharist->json('data.person_id'));
        $this->assertSame($people, Person::query()->count());
    }

    #[Test]
    public function possible_person_match_requires_explicit_choice(): void
    {
        Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Anna',
            'last_name' => 'Unaffiliated',
            'date_of_birth' => '2018-01-01',
            'gender' => 'female',
        ]);

        $response = $this->postJson('/api/sacraments', array_merge($this->identityFields([
            'recipient_birth_date' => '2018-01-01',
            'recipient_gender' => 'female',
        ]), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'family_association' => 'none',
            'person' => [
                'first_name' => 'Anna',
                'last_name' => 'Unaffiliated',
                'date_of_birth' => '2018-01-01',
                'place_of_birth' => 'Parish City',
                'gender' => 'female',
                'father_name' => 'Joseph Thomas',
                'mother_name' => 'Mary Thomas',
            ],
            'participants' => $this->minister(),
        ]));

        $response->assertStatus(409)->assertJsonPath('code', 'possible_person_match');
        $this->assertNotEmpty($response->json('context.matches'));
    }

    #[Test]
    public function existing_person_is_not_overwritten_by_sacrament_form(): void
    {
        $person = $this->member->person;
        $originalDob = optional($person->date_of_birth)?->format('Y-m-d');

        $this->postJson('/api/sacraments', array_merge($this->identityFields([
            'recipient_birth_date' => '2015-04-11',
            'recipient_birth_place' => 'Other City',
        ]), [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'family_association' => 'existing',
            'family_id' => $this->family->id,
            'family_member_id' => $this->member->id,
            'person' => [
                'first_name' => 'John',
                'last_name' => 'Thomas',
                'date_of_birth' => '2015-04-11',
            ],
            'participants' => $this->minister(),
        ]))->assertCreated();

        $person->refresh();
        $this->assertSame($originalDob, optional($person->date_of_birth)?->format('Y-m-d'));
    }

    #[Test]
    public function rejects_cross_tenant_person(): void
    {
        $other = Person::factory()->create(['tenant_id' => $this->otherTenant->id]);

        $this->postJson('/api/sacraments', [
            'sacrament_type_id' => $this->baptismType->id,
            'date_administered' => '2026-08-01',
            'family_association' => 'none',
            'person_id' => $other->id,
            'recipient_birth_date' => '2018-01-01',
            'recipient_birth_place' => 'Parish City',
            'recipient_gender' => 'male',
            'participants' => $this->minister(),
        ])->assertStatus(422);
    }

    #[Test]
    public function backfill_creates_one_person_per_member(): void
    {
        $stats = app(PersonBackfillService::class)->run((int) $this->tenant->id);
        $this->assertSame(0, $stats['created']);
        $this->assertNotNull($this->member->fresh()->person_id);
    }
}
