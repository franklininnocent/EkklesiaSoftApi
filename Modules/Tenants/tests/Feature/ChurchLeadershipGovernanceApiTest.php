<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class ChurchLeadershipGovernanceApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private ChurchProfile $profile;

    private LeadershipRole $pastorRole;

    private LeadershipRole $deaconRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeadershipRolesSeeder::class);

        $this->tenant = $this->makeOperationalTenant();
        $this->profile = ChurchProfile::factory()->create(['tenant_id' => $this->tenant->id]);

        $adminBundle = $this->makeTenantPersona(
            $this->tenant,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->admin = $adminBundle['user'];

        $this->pastorRole = LeadershipRole::query()->where('title', 'Pastor')->firstOrFail();
        $this->deaconRole = LeadershipRole::query()->where('title', 'Deacon')->firstOrFail();
    }

    private function authenticateAdmin(): void
    {
        Passport::actingAs($this->admin);
    }

    private function makePerson(string $first = 'John', string $last = 'Smith'): Person
    {
        return Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => $first,
            'last_name' => $last,
            'created_by' => $this->admin->id,
        ]);
    }

    #[Test]
    public function assignment_profile_photo_can_be_uploaded_after_assign(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson('Photo', 'Leader');

        $assignment = LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subMonth()->toDateString(),
        ]);

        $response = $this->postJson(
            "/api/church-profile/leadership/assignments/{$assignment->id}/photo",
            [],
            ['Content-Type' => 'multipart/form-data'],
        );

        $response->assertStatus(422);

        $file = UploadedFile::fake()->image('leader.jpg');

        $upload = $this->postJson(
            "/api/church-profile/leadership/assignments/{$assignment->id}/photo",
            ['image' => $file],
        );

        $upload->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.person.photo_url', fn ($value) => is_string($value) && $value !== '');

        $current = $this->getJson('/api/church-profile/leadership/current');
        $current->assertOk()
            ->assertJsonPath('data.assignments.0.person.photo_full_url', fn ($value) => is_string($value) && $value !== '');
    }

    #[Test]
    public function current_assignment_includes_leader_photo_from_legacy_record(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson('Anto', 'Leader');

        $legacy = ChurchLeadership::query()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Rev.Fr.Anto Leader',
            'role' => 'Pastor',
            'title' => 'Rev.Fr.',
            'photo_url' => 'church-leadership/test-pastor.jpg',
            'is_primary' => 1,
            'display_order' => 0,
            'active' => 1,
        ]);

        LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'legacy_church_leadership_id' => $legacy->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $response = $this->getJson('/api/church-profile/leadership/current');
        $response->assertOk()
            ->assertJsonPath('data.assignments.0.person.photo_url', 'church-leadership/test-pastor.jpg')
            ->assertJsonPath('data.assignments.0.person.photo_full_url', url('storage/church-leadership/test-pastor.jpg'));
    }

    #[Test]
    public function active_assignment_appears_in_current_not_ended_in_history_only(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson();

        $active = LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $ended = LeadershipAssignment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $this->makePerson('Mary', 'Jones')->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => now()->subYears(3)->toDateString(),
        ]);

        $current = $this->getJson('/api/church-profile/leadership/current');
        $current->assertOk()->assertJsonPath('success', true);
        $currentIds = collect($current->json('data.assignments'))->pluck('id');
        $this->assertTrue($currentIds->contains($active->id));
        $this->assertFalse($currentIds->contains($ended->id));

        $history = $this->getJson('/api/church-profile/leadership/history');
        $history->assertOk();
        $historyIds = collect($history->json('data'))->pluck('id');
        $this->assertTrue($historyIds->contains($ended->id));
        $this->assertTrue($historyIds->contains($active->id));
    }

    #[Test]
    public function handover_closes_outgoing_and_opens_incoming(): void
    {
        $this->authenticateAdmin();
        $personA = $this->makePerson('Alpha', 'Priest');
        $personB = $this->makePerson('Beta', 'Priest');

        $outgoing = LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $personA->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2020-01-01',
        ]);

        $response = $this->postJson('/api/church-profile/leadership/handover', [
            'outgoing_assignment_id' => $outgoing->id,
            'outgoing_end_date' => '2025-05-31',
            'outgoing_exit_reason_code' => 'transferred',
            'person_id' => $personB->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2025-06-01',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $outgoing->refresh();
        $this->assertSame(LeadershipAssignmentStatus::TRANSFERRED, $outgoing->status);
        $this->assertSame('2025-05-31', $outgoing->end_date->toDateString());

        $incomingId = $response->json('data.incoming.id');
        $incoming = LeadershipAssignment::query()->findOrFail($incomingId);
        $this->assertSame(LeadershipAssignmentStatus::ACTIVE, $incoming->status);
        $this->assertSame('2025-06-01', $incoming->start_date->toDateString());
    }

    #[Test]
    public function concurrent_role_allows_multiple_active_assignments(): void
    {
        $this->authenticateAdmin();
        $personA = $this->makePerson('Deacon', 'One');
        $personB = $this->makePerson('Deacon', 'Two');

        $first = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $personA->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2024-01-01',
        ]);
        $first->assertCreated();

        $second = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $personB->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2024-02-01',
        ]);
        $second->assertCreated();

        $current = $this->getJson('/api/church-profile/leadership/current');
        $deaconCount = collect($current->json('data.assignments'))
            ->where('role_id', $this->deaconRole->id)
            ->count();
        $this->assertSame(2, $deaconCount);
    }

    #[Test]
    public function non_concurrent_assign_returns_conflict_with_incumbent(): void
    {
        $this->authenticateAdmin();
        $incumbentPerson = $this->makePerson('Current', 'Pastor');
        $newPerson = $this->makePerson('New', 'Pastor');

        LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $incumbentPerson->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2023-06-01',
        ]);

        $response = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $newPerson->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2025-06-01',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.incumbent.person.id', $incumbentPerson->id);
    }

    #[Test]
    public function as_of_query_returns_assignment_active_on_date(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson();

        LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2018-01-01',
            'end_date' => '2023-05-31',
            'status' => LeadershipAssignmentStatus::TRANSFERRED,
        ]);

        $response = $this->getJson('/api/church-profile/leadership/history?as_of=2020-01-01');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($person->id, $response->json('data.0.person.id'));
    }

    #[Test]
    public function cross_tenant_access_is_blocked(): void
    {
        $otherTenant = $this->makeOperationalTenant();
        $otherProfile = ChurchProfile::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherPerson = Person::factory()->create(['tenant_id' => $otherTenant->id]);

        LeadershipAssignment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'church_profile_id' => $otherProfile->id,
            'person_id' => $otherPerson->id,
            'role_id' => $this->pastorRole->id,
        ]);

        $this->authenticateAdmin();

        $this->getJson('/api/church-profile/leadership/current')->assertOk();
        $ids = collect($this->getJson('/api/church-profile/leadership/current')->json('data.assignments'))->pluck('tenant_id');
        $this->assertFalse($ids->contains($otherTenant->id));
    }

    #[Test]
    public function person_soft_delete_preserves_historical_assignment(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson();

        $assignment = LeadershipAssignment::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'start_date' => '2019-01-01',
        ]);

        $person->delete();

        $response = $this->getJson('/api/church-profile/leadership/history');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $assignment->id);
        $this->assertNotNull($row);
        $this->assertSame($person->id, $row['person']['id']);
    }

    #[Test]
    public function invalid_end_date_before_start_is_rejected(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson();

        $response = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-06-01',
            'end_date' => '2025-01-01',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function unauthorized_user_cannot_assign_or_terminate(): void
    {
        $staffRole = Role::create([
            'name' => 'Parish Staff',
            'description' => 'Staff',
            'level' => 2,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $staffRole->id,
        ]);
        $staff->syncRoles([$staffRole->id]);

        Passport::actingAs($staff);

        $person = $this->makePerson();

        $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-01-01',
        ])->assertForbidden();
    }

    #[Test]
    public function assign_and_terminate_write_audit_logs(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson();

        $assign = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-01-01',
        ]);
        $assign->assertCreated();
        $assignmentId = $assign->json('data.id');

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'leadership.assigned',
            'target_id' => $assignmentId,
        ]);

        $this->putJson("/api/church-profile/leadership/assignments/{$assignmentId}/terminate", [
            'end_date' => '2025-12-31',
            'exit_reason_code' => 'completed',
        ])->assertOk();

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'leadership.terminated',
            'target_id' => $assignmentId,
        ]);
    }

    #[Test]
    public function assign_existing_parishioner_returns_created(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson('Existing', 'Parishioner');
        $personCountBefore = Person::query()->where('tenant_id', $this->tenant->id)->count();

        $response = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.person_id', $person->id);

        $this->assertSame($personCountBefore, Person::query()->where('tenant_id', $this->tenant->id)->count());
    }

    #[Test]
    public function assign_external_leader_creates_person_and_assignment(): void
    {
        $this->authenticateAdmin();
        $personCountBefore = Person::query()->where('tenant_id', $this->tenant->id)->count();

        $response = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => true,
            'first_name' => 'Visiting',
            'last_name' => 'Priest',
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-03-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.person.first_name', 'Visiting')
            ->assertJsonPath('data.person.last_name', 'Priest');

        $personId = $response->json('data.person_id');
        $this->assertNotNull($personId);
        $this->assertSame($personCountBefore + 1, Person::query()->where('tenant_id', $this->tenant->id)->count());

        $this->assertDatabaseHas('persons', [
            'id' => $personId,
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Visiting',
            'last_name' => 'Priest',
        ]);

        $this->assertDatabaseMissing('family_members', [
            'person_id' => $personId,
        ]);

        $this->assertDatabaseHas('leadership_assignments', [
            'person_id' => $personId,
            'role_id' => $this->deaconRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
        ]);
    }

    #[Test]
    public function assign_external_leader_without_names_is_rejected(): void
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => true,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-03-01',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function active_assignment_can_be_updated(): void
    {
        $this->authenticateAdmin();
        $person = $this->makePerson('Original', 'Name');

        $assign = $this->postJson('/api/church-profile/leadership/assign', [
            'is_external' => false,
            'person_id' => $person->id,
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-01-01',
            'jurisdiction_name' => 'Old Diocese',
        ]);
        $assign->assertCreated();
        $assignmentId = $assign->json('data.id');

        $response = $this->putJson("/api/church-profile/leadership/assignments/{$assignmentId}", [
            'first_name' => 'Updated',
            'last_name' => 'Leader',
            'role_id' => $this->deaconRole->id,
            'start_date' => '2025-02-01',
            'appointment_date' => '2025-01-15',
            'jurisdiction_name' => 'New Diocese',
            'appointment_letter_ref' => 'DEC-2025-01',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.person.first_name', 'Updated')
            ->assertJsonPath('data.person.last_name', 'Leader')
            ->assertJsonPath('data.start_date', '2025-02-01')
            ->assertJsonPath('data.jurisdiction_name', 'New Diocese')
            ->assertJsonPath('data.appointment_letter_ref', 'DEC-2025-01');

        $this->assertDatabaseHas('persons', [
            'id' => $person->id,
            'first_name' => 'Updated',
            'last_name' => 'Leader',
        ]);

        $this->assertDatabaseHas('church_audit_logs', [
            'tenant_id' => $this->tenant->id,
            'event' => 'leadership.updated',
            'target_id' => $assignmentId,
        ]);
    }
}
