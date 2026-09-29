<?php

namespace Modules\PastoralCare\Tests\Feature;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\PastoralCare\Database\Seeders\PastoralCarePermissionSeeder;
use Modules\PastoralCare\Models\PastoralCareRequest;
use Modules\PastoralCare\Support\PastoralCareStatus;
use Modules\PastoralCare\Support\PastoralCareType;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PastoralCareApiTest extends TestCase
{
    private Tenant $tenant;

    private User $staff;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->staff = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fr. James',
            'active' => 1,
        ]);
        $this->family = Family::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_name' => 'Santos Family',
            'status' => 'active',
        ]);

        Passport::actingAs($this->staff);
        $this->grantPermissions($this->staff, [
            'pastoral.care.view',
            'pastoral.care.create',
            'pastoral.care.assign',
        ]);
    }

    #[Test]
    public function staff_can_create_a_visit_request_for_a_family(): void
    {
        $response = $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::HOSPITAL_VISIT,
            'priority' => 'urgent',
            'summary' => 'Call Maria Santos — surgery recovery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PastoralCareStatus::OPEN)
            ->assertJsonPath('data.family_id', $this->family->id)
            ->assertJsonPath('data.assignee', 'Unassigned');

        $this->assertDatabaseHas('pastoral_care_requests', [
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'created_by_user_id' => $this->staff->id,
            'status' => PastoralCareStatus::OPEN,
        ]);
    }

    #[Test]
    public function staff_can_assign_a_named_user_not_a_generic_label(): void
    {
        $assignee = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deacon Paul',
            'active' => 1,
        ]);

        $created = $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::HOME_VISIT,
            'summary' => 'Home visit — new baby',
        ])->json('data.id');

        $this->postJson("/api/tenant/pastoral/requests/{$created}/assign", [
            'assigned_to_user_id' => $assignee->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', PastoralCareStatus::ASSIGNED)
            ->assertJsonPath('data.assigned_to_user_id', $assignee->id)
            ->assertJsonPath('data.assignee', 'Deacon Paul');
    }

    #[Test]
    public function assignee_can_mark_done_and_view_only_user_cannot_create(): void
    {
        $assignee = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sister Anne',
            'active' => 1,
        ]);
        $this->grantPermissions($assignee, ['pastoral.care.view']);

        $created = $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::BEREAVEMENT,
            'summary' => 'Bereavement follow-up',
        ])->json('data.id');

        $this->postJson("/api/tenant/pastoral/requests/{$created}/assign", [
            'assigned_to_user_id' => $assignee->id,
        ])->assertOk();

        Passport::actingAs($assignee);
        $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::OTHER,
            'summary' => 'Should fail',
        ])->assertForbidden();

        $this->postJson("/api/tenant/pastoral/requests/{$created}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', PastoralCareStatus::DONE);
    }

    #[Test]
    public function requests_are_isolated_by_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'active' => 1]);
        $otherFamily = Family::factory()->create(['tenant_id' => $otherTenant->id]);
        $this->grantPermissions($otherUser, [
            'pastoral.care.view',
            'pastoral.care.create',
            'pastoral.care.assign',
        ]);

        $id = $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::HOME_VISIT,
            'summary' => 'Visible only in home parish',
        ])->json('data.id');

        Passport::actingAs($otherUser);
        $this->getJson('/api/tenant/pastoral/requests')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->getJson("/api/tenant/pastoral/requests/{$id}")
            ->assertNotFound();

        $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::HOME_VISIT,
            'summary' => 'Cross-tenant family',
        ])->assertNotFound();
    }

    #[Test]
    public function parishioner_linked_user_cannot_create_a_request(): void
    {
        $person = Person::factory()->create(['tenant_id' => $this->tenant->id]);
        FamilyMember::factory()->create([
            'family_id' => $this->family->id,
            'person_id' => $person->id,
            'status' => 'active',
        ]);
        $parishioner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'person_id' => $person->id,
            'active' => 1,
        ]);
        $this->grantPermissions($parishioner, [
            'pastoral.care.view',
            'pastoral.care.create',
            'pastoral.care.assign',
        ]);

        Passport::actingAs($parishioner);
        $this->postJson('/api/tenant/pastoral/requests', [
            'family_id' => $this->family->id,
            'type' => PastoralCareType::HOME_VISIT,
            'summary' => 'Member self-service must be blocked',
        ])->assertForbidden();

        $staff = $this->getJson('/api/tenant/pastoral/staff')->json('data');
        $ids = collect($staff)->pluck('id')->all();
        $this->assertNotContains($parishioner->id, $ids);
        $this->assertContains($this->staff->id, $ids);
    }

    #[Test]
    public function dashboard_returns_open_tasks_and_permission_seeder_grants_leaders(): void
    {
        PastoralCareRequest::factory()->create([
            'tenant_id' => $this->tenant->id,
            'family_id' => $this->family->id,
            'created_by_user_id' => $this->staff->id,
            'type' => PastoralCareType::HOSPITAL_VISIT,
            'priority' => 'urgent',
            'status' => PastoralCareStatus::OPEN,
            'summary' => 'Hospital visit',
        ]);

        $this->getJson('/api/tenant/pastoral/dashboard')
            ->assertOk()
            ->assertJsonPath('data.open_count', 1)
            ->assertJsonPath('data.tasks.0.family_name', 'Santos Family');

        $role = Role::create([
            'name' => 'Parish Priest',
            'description' => 'Priest',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        (new PastoralCarePermissionSeeder)->run();
        $role->refresh();
        $this->assertTrue(
            $role->permissions()->where('name', 'pastoral.care.assign')->exists()
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(User $user, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'PastoralCare',
                    'category' => 'pastoral',
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'tenant_id' => null,
                    'is_custom' => false,
                ]
            );
            $ids[] = $permission->id;
        }

        $user->permissions()->syncWithoutDetaching($ids);
        $user->clearPermissionsCache();
        if (method_exists($user, 'clearRequestPermissionCache')) {
            $user->clearRequestPermissionCache();
        }
    }
}
