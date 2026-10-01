<?php

namespace Modules\Family\Tests\Feature;

use Mockery;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\app\Services\FamilyService;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Testing\FamilyCertificationTestCase;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;

class FamilyMemberMarriageDateSyncTest extends FamilyCertificationTestCase
{
    #[Test]
    public function it_persists_marriage_date_when_editing_family_head(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2012-05-18',
            'baptism_date' => '1980-06-01',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('2012-05-18', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2012-05-18', $household['spouse']->fresh()->marriage_date?->toDateString());
        $this->assertDatabaseHas('family_audit_logs', [
            'event' => 'family_member.marriage_date_updated',
            'target_id' => $household['head']->id,
        ]);
        $this->assertDatabaseHas('family_audit_logs', [
            'event' => 'family_member.marriage_date_updated',
            'target_id' => $household['spouse']->id,
        ]);
    }

    #[Test]
    public function it_persists_marriage_date_when_editing_spouse_member(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['spouse']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2011-11-02',
            'baptism_date' => '1982-03-10',
        ])->assertOk();

        $this->assertSame('2011-11-02', $household['spouse']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2011-11-02', $household['head']->fresh()->marriage_date?->toDateString());
    }

    #[Test]
    public function family_show_suggests_current_member_marriage_date(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2008-04-12', spouseDate: '2008-04-12');

        $response = $this->getJson("/api/families/{$household['family']->id}");
        $response->assertOk();

        $head = $this->memberFromShow($response, $household['head']->id);
        $this->assertSame('2008-04-12', $head['marriage_date']);
        $this->assertSame('2008-04-12', $head['suggested_marriage_date']);
        $this->assertFalse($head['marriage_date_conflict']);
        $this->assertSame($household['spouse']->id, $head['linked_spouse_member_id']);
    }

    #[Test]
    public function family_show_suggests_spouse_date_when_member_date_is_empty(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], spouseDate: '2009-08-20');

        $head = $this->memberFromShow(
            $this->getJson("/api/families/{$household['family']->id}"),
            $household['head']->id
        );

        $this->assertSame('2009-08-20', $head['marriage_date']);
        $this->assertSame('2009-08-20', $head['suggested_marriage_date']);
        $this->assertSame('2009-08-20', $head['linked_spouse_marriage_date']);
        $this->assertFalse($head['marriage_date_conflict']);
        $this->assertSame($household['spouse']->full_name_display, $head['marriage_spouse_name']);
    }

    #[Test]
    public function family_show_suggests_matrimony_register_date_when_profile_dates_are_empty(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);
        $this->createMatrimonyRecord($ctx['tenant'], $household, '2015-06-21', 'MATRIMONY');

        $head = $this->memberFromShow(
            $this->getJson("/api/families/{$household['family']->id}"),
            $household['head']->id
        );

        $this->assertNull($household['head']->fresh()->marriage_date);
        $this->assertSame('2015-06-21', $head['marriage_date']);
        $this->assertSame('2015-06-21', $head['suggested_marriage_date']);
        $this->assertSame('2015-06-21', $head['matrimony_register_date']);
    }

    #[Test]
    public function family_show_reads_lowercase_marriage_sacrament_code(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);
        $this->createMatrimonyRecord($ctx['tenant'], $household, '2016-02-14', 'marriage');

        $spouse = $this->memberFromShow(
            $this->getJson("/api/families/{$household['family']->id}"),
            $household['spouse']->id
        );

        $this->assertSame('2016-02-14', $spouse['suggested_marriage_date']);
        $this->assertSame('2016-02-14', $spouse['matrimony_register_date']);
        $this->assertSame('2016-02-14', $spouse['marriage_date']);
    }

    #[Test]
    public function family_show_overlays_shared_marriage_profile_onto_linked_spouse(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], spouseDate: '2013-09-28');
        $household['spouse']->update([
            'marriage_place' => 'Sacred Heart Church',
            'marriage_spouse_name' => $household['head']->full_name_display,
            'marriage_bride_full_name' => $household['spouse']->full_name_display,
            'marriage_groom_full_name' => $household['head']->full_name_display,
        ]);

        $response = $this->getJson("/api/families/{$household['family']->id}");
        $head = $this->memberFromShow($response, $household['head']->id);
        $spouse = $this->memberFromShow($response, $household['spouse']->id);

        $this->assertSame('2013-09-28', $head['marriage_date']);
        $this->assertSame('Sacred Heart Church', $head['marriage_place']);
        $this->assertSame($household['spouse']->full_name_display, $head['marriage_spouse_name']);
        $this->assertSame($household['spouse']->full_name_display, $head['linked_spouse_name']);
        $this->assertTrue($head['marriage_resolved_from_spouse']);
        $this->assertFalse($head['marriage_resolution_needed']);

        $this->assertSame('2013-09-28', $spouse['marriage_date']);
        $this->assertSame('Sacred Heart Church', $spouse['marriage_place']);
        $this->assertSame($household['head']->full_name_display, $spouse['marriage_spouse_name']);
        $this->assertNull($household['head']->fresh()->marriage_date);
        $this->assertNull($household['head']->fresh()->marriage_place);
    }

    #[Test]
    public function updating_marriage_place_from_either_spouse_updates_the_linked_profile(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2013-09-28', spouseDate: '2013-09-28');
        $household['spouse']->update(['marriage_place' => 'Sacred Heart Church']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2013-09-28',
            'marriage_place' => 'Sacred Heart Church',
            'baptism_date' => '1975-06-15',
        ])->assertOk();

        $this->assertSame('Sacred Heart Church', $household['head']->fresh()->marriage_place);
        $this->assertSame('Sacred Heart Church', $household['spouse']->fresh()->marriage_place);
        $this->assertSame($household['head']->full_name_display, $household['spouse']->fresh()->marriage_spouse_name);
        $this->assertSame($household['spouse']->full_name_display, $household['head']->fresh()->marriage_spouse_name);
    }

    #[Test]
    public function ambiguous_household_does_not_copy_marriage_onto_another_member(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], spouseDate: '2013-09-28');
        $household['spouse']->update(['marriage_place' => 'Sacred Heart Church']);
        FamilyMember::factory()->active()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $household['family']->id,
            'relationship_to_head' => 'spouse',
            'marital_status' => 'married',
            'status' => 'active',
        ]);

        $head = $this->memberFromShow(
            $this->getJson("/api/families/{$household['family']->id}"),
            $household['head']->id
        );

        $this->assertNull($head['marriage_date']);
        $this->assertTrue($head['marriage_resolution_needed']);
        $this->assertNull($head['linked_spouse_member_id']);
    }

    #[Test]
    public function cousin_with_a_marriage_date_is_not_treated_as_the_household_spouse(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);
        FamilyMember::factory()->active()->create([
            'tenant_id' => $ctx['tenant']->id,
            'family_id' => $household['family']->id,
            'relationship_to_head' => 'cousin',
            'marital_status' => 'married',
            'marriage_date' => '1999-01-01',
            'marriage_place' => 'Another Parish',
            'status' => 'active',
        ]);

        $head = $this->memberFromShow(
            $this->getJson("/api/families/{$household['family']->id}"),
            $household['head']->id
        );

        $this->assertNull($head['marriage_date']);
        $this->assertFalse($head['marriage_resolution_needed']);
        $this->assertSame($household['spouse']->id, $head['linked_spouse_member_id']);
    }

    #[Test]
    public function saving_without_linked_spouse_does_not_change_other_members(): void
    {
        $ctx = $this->authenticateAsStaff();
        $family = Family::factory()->create(['tenant_id' => $ctx['tenant']->id]);
        $head = FamilyMember::factory()->head()->active()->create([
            'family_id' => $family->id,
            'marital_status' => 'married',
            'baptism_date' => '1970-01-01',
        ]);
        $cousin = FamilyMember::factory()->active()->create([
            'family_id' => $family->id,
            'relationship_to_head' => 'cousin',
            'marital_status' => 'single',
            'marriage_date' => '1999-01-01',
            'baptism_date' => '1980-01-01',
        ]);

        $this->putJson("/api/families/{$family->id}/members/{$head->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2010-01-15',
            'baptism_date' => '1970-01-01',
        ])->assertOk();

        $this->assertSame('2010-01-15', $head->fresh()->marriage_date?->toDateString());
        $this->assertSame('1999-01-01', $cousin->fresh()->marriage_date?->toDateString());
    }

    #[Test]
    public function conflicting_profile_dates_require_acknowledgement(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2001-01-01', spouseDate: '2002-02-02');

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2001-01-01',
            'baptism_date' => '1975-06-15',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.conflict.0', 'marriage_date_conflict');

        $this->assertSame('2001-01-01', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2002-02-02', $household['spouse']->fresh()->marriage_date?->toDateString());
    }

    #[Test]
    public function acknowledging_conflict_aligns_profiles_and_leaves_register_unchanged(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2001-01-01', spouseDate: '2002-02-02');
        $sacrament = $this->createMatrimonyRecord($ctx['tenant'], $household, '2000-12-24', 'MATRIMONY');

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2003-03-03',
            'baptism_date' => '1975-06-15',
            'acknowledge_marriage_date_conflict' => true,
        ])->assertOk();

        $this->assertSame('2003-03-03', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2003-03-03', $household['spouse']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2000-12-24', $sacrament->fresh()->date_administered?->toDateString());
        $this->assertSame(1, Sacrament::query()->where('id', $sacrament->id)->count());
    }

    #[Test]
    public function changing_status_from_married_preserves_marriage_date_and_register(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2005-07-07', spouseDate: '2005-07-07');
        $sacrament = $this->createMatrimonyRecord($ctx['tenant'], $household, '2005-07-07', 'MATRIMONY');

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'widowed',
            'marriage_date' => null,
        ])->assertOk();

        $this->assertSame('widowed', $household['head']->fresh()->marital_status);
        $this->assertSame('2005-07-07', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2005-07-07', $household['spouse']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2005-07-07', $sacrament->fresh()->date_administered?->toDateString());
    }

    #[Test]
    public function it_rejects_invalid_and_future_marriage_dates(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => 'not-a-date',
        ])->assertStatus(422)->assertJsonValidationErrors(['marriage_date']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['marriage_date']);
    }

    #[Test]
    public function unauthenticated_users_cannot_update_marriage_date(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);
        $this->app['auth']->forgetGuards();

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2010-01-01',
        ])->assertUnauthorized();
    }

    #[Test]
    public function users_without_families_edit_cannot_update_marriage_date(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $household = $this->marriedHousehold($tenant);
        $user = \Modules\Authentication\Models\User::factory()->tenantUser($tenant->id)->create();
        $this->grantFamilyPermissions($user, ['families.view']);
        \Laravel\Passport\Passport::actingAs($user);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2010-01-01',
        ])->assertForbidden();
    }

    #[Test]
    public function parishioners_cannot_update_marriage_date(): void
    {
        $tenant = Tenant::factory()->active()->create();
        $household = $this->marriedHousehold($tenant);
        $this->authenticateAsParishioner($tenant, $household['persons']['head']);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2010-01-01',
        ])->assertForbidden();
    }

    #[Test]
    public function it_returns_404_for_cross_tenant_member_updates(): void
    {
        $ctx = $this->authenticateAsStaff();
        $otherTenant = Tenant::factory()->active()->create();
        $otherHousehold = $this->marriedHousehold($otherTenant);

        $this->putJson("/api/families/{$otherHousehold['family']->id}/members/{$otherHousehold['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2010-01-01',
        ])->assertNotFound();

        $this->assertNull($otherHousehold['head']->fresh()->marriage_date);
    }

    #[Test]
    public function it_does_not_update_a_cross_tenant_register_participant(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant']);
        $otherTenant = Tenant::factory()->active()->create();
        $foreign = $this->marriedHousehold($otherTenant, headDate: '1990-01-01');

        $type = SacramentType::factory()->create([
            'name' => 'Matrimony',
            'code' => 'MATRIMONY',
            'active' => true,
        ]);
        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $ctx['tenant']->id,
            'sacrament_type_id' => $type->id,
            'date_administered' => '2014-09-09',
            'status' => SacramentStatus::REGISTERED,
        ]);
        $sacrament->participants()->create([
            'tenant_id' => $ctx['tenant']->id,
            'role' => 'groom',
            'source' => 'member',
            'family_member_id' => $household['head']->id,
            'person_id' => $household['head']->person_id,
        ]);
        $sacrament->participants()->create([
            'tenant_id' => $ctx['tenant']->id,
            'role' => 'bride',
            'source' => 'member',
            'family_member_id' => $foreign['head']->id,
            'person_id' => $foreign['head']->person_id,
        ]);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2014-09-09',
            'baptism_date' => '1975-06-15',
            'acknowledge_marriage_date_conflict' => true,
        ])->assertOk();

        $this->assertSame('2014-09-09', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('1990-01-01', $foreign['head']->fresh()->marriage_date?->toDateString());
    }

    #[Test]
    public function it_rolls_back_when_linked_spouse_update_fails(): void
    {
        $ctx = $this->authenticateAsStaff();
        $household = $this->marriedHousehold($ctx['tenant'], headDate: '2008-01-01', spouseDate: '2008-01-01');
        $spouseId = (string) $household['spouse']->id;

        $real = app(FamilyRepository::class);
        $mock = Mockery::mock($real)->makePartial();
        $mock->shouldReceive('updateMember')->andReturnUsing(function (FamilyMember $member, array $data) use ($spouseId) {
            if ((string) $member->id === $spouseId) {
                throw new \RuntimeException('forced spouse failure');
            }

            return $member->update($data);
        });
        $this->app->instance(FamilyRepository::class, $mock);
        $this->app->forgetInstance(\Modules\Family\app\Services\MarriageDateSyncService::class);
        $this->app->forgetInstance(FamilyService::class);

        $this->putJson("/api/families/{$household['family']->id}/members/{$household['head']->id}", [
            'marital_status' => 'married',
            'marriage_date' => '2018-08-08',
            'baptism_date' => '1975-06-15',
        ])->assertStatus(500);

        $this->assertSame('2008-01-01', $household['head']->fresh()->marriage_date?->toDateString());
        $this->assertSame('2008-01-01', $household['spouse']->fresh()->marriage_date?->toDateString());
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{family: Family, head: FamilyMember, spouse: FamilyMember, child: FamilyMember, persons: array<string, mixed>}
     */
    private function marriedHousehold(
        Tenant $tenant,
        ?string $headDate = null,
        ?string $spouseDate = null,
        array $opts = []
    ): array {
        $household = $this->seedHousehold($tenant, $opts);
        $household['head']->update([
            'marital_status' => 'married',
            'baptism_date' => '1975-06-15',
            'marriage_date' => $headDate,
        ]);
        $household['spouse']->update([
            'marital_status' => 'married',
            'baptism_date' => '1978-03-20',
            'marriage_date' => $spouseDate,
        ]);

        $household['head'] = $household['head']->fresh();
        $household['spouse'] = $household['spouse']->fresh();

        return $household;
    }

    /**
     * @param  array{family: Family, head: FamilyMember, spouse: FamilyMember}  $household
     */
    private function createMatrimonyRecord(Tenant $tenant, array $household, string $date, string $code): Sacrament
    {
        $type = SacramentType::factory()->create([
            'name' => 'Matrimony',
            'code' => $code,
            'active' => true,
        ]);

        $sacrament = Sacrament::factory()->registered()->create([
            'tenant_id' => $tenant->id,
            'family_id' => $household['family']->id,
            'sacrament_type_id' => $type->id,
            'date_administered' => $date,
            'status' => SacramentStatus::REGISTERED,
        ]);

        $sacrament->participants()->create([
            'tenant_id' => $tenant->id,
            'role' => 'groom',
            'source' => 'member',
            'family_member_id' => $household['head']->id,
            'person_id' => $household['head']->person_id,
        ]);
        $sacrament->participants()->create([
            'tenant_id' => $tenant->id,
            'role' => 'bride',
            'source' => 'member',
            'family_member_id' => $household['spouse']->id,
            'person_id' => $household['spouse']->person_id,
        ]);

        return $sacrament;
    }

    /**
     * @return array<string, mixed>
     */
    private function memberFromShow(\Illuminate\Testing\TestResponse $response, string $memberId): array
    {
        $members = collect($response->json('data.members'));
        $member = $members->firstWhere('id', $memberId);
        $this->assertIsArray($member);

        if (isset($member['marriage_date']) && is_string($member['marriage_date']) && str_contains($member['marriage_date'], 'T')) {
            $member['marriage_date'] = explode('T', $member['marriage_date'])[0];
        }

        return $member;
    }
}
