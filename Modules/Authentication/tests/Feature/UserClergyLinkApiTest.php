<?php

namespace Modules\Authentication\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Person;
use Modules\Tenants\Database\Seeders\LeadershipRolesSeeder;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\LeadershipAssignmentStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ActsAsTenantRoles;
use Tests\TestCase;

class UserClergyLinkApiTest extends TestCase
{
    use ActsAsTenantRoles;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private ChurchProfile $profile;

    private LeadershipRole $pastorRole;

    private Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LeadershipRolesSeeder::class);

        $this->tenant = $this->makeOperationalTenant();
        $this->profile = ChurchProfile::factory()->create(['tenant_id' => $this->tenant->id]);

        $adminBundle = $this->makeTenantPersona(
            $this->tenant,
            Role::TENANT_ADMINISTRATOR,
            array_merge($this->parishAdminPermissionNames(), ['users.create', 'users.update']),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $this->admin = $adminBundle['user'];

        $this->pastorRole = LeadershipRole::query()->where('title', 'Pastor')->firstOrFail();

        $this->staffRole = Role::query()->firstOrCreate(
            [
                'name' => 'Parish Priest',
                'tenant_id' => $this->tenant->id,
            ],
            [
                'description' => 'Parish Priest',
                'level' => 2,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_TENANT,
            ]
        );
    }

    private function authenticateAdmin(): void
    {
        Passport::actingAs($this->admin);
    }

    private function makeClergyPerson(string $first = 'Paul', string $last = 'Pastor'): Person
    {
        return Person::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first).'.'.strtolower($last).'@parish.test',
            'phone' => '9876543210',
            'created_by' => $this->admin->id,
        ]);
    }

    private function assignClergy(Person $person): LeadershipAssignment
    {
        return LeadershipAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'church_profile_id' => $this->profile->id,
            'person_id' => $person->id,
            'role_id' => $this->pastorRole->id,
            'status' => LeadershipAssignmentStatus::ACTIVE,
            'start_date' => now()->subMonth()->toDateString(),
        ]);
    }

    #[Test]
    public function linkable_clergy_lists_active_unlinked_parish_clergy(): void
    {
        $this->authenticateAdmin();

        $person = $this->makeClergyPerson('Anna', 'Clergy');
        $this->assignClergy($person);

        $response = $this->getJson('/api/users/linkable-clergy?search=Anna');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.person_id', $person->id)
            ->assertJsonPath('data.0.person_name', 'Anna Clergy');
    }

    #[Test]
    public function store_user_links_active_parish_clergy_person(): void
    {
        $this->authenticateAdmin();

        $person = $this->makeClergyPerson('Mark', 'Shepherd');
        $this->assignClergy($person);

        $response = $this->postJson('/api/users', [
            'name' => 'Mark Shepherd',
            'email' => 'mark.shepherd@parish.test',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'role_ids' => [$this->staffRole->id],
            'active' => 1,
            'person_id' => $person->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.person_id', $person->id);

        $this->assertDatabaseHas('users', [
            'email' => 'mark.shepherd@parish.test',
            'tenant_id' => $this->tenant->id,
            'person_id' => $person->id,
        ]);
    }

    #[Test]
    public function store_user_rejects_person_without_active_clergy_assignment(): void
    {
        $this->authenticateAdmin();

        $person = $this->makeClergyPerson('No', 'Assignment');

        $response = $this->postJson('/api/users', [
            'name' => 'No Assignment',
            'email' => 'no.assignment@parish.test',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'role_ids' => [$this->staffRole->id],
            'active' => 1,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.person_id.0', fn ($message) => str_contains((string) $message, 'active parish clergy'));
    }

    #[Test]
    public function store_user_rejects_person_already_linked_to_another_login(): void
    {
        $this->authenticateAdmin();

        $person = $this->makeClergyPerson('Taken', 'Leader');
        $this->assignClergy($person);

        User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'person_id' => $person->id,
            'email' => 'existing@parish.test',
            'active' => 1,
        ]);

        $response = $this->postJson('/api/users', [
            'name' => 'Taken Leader',
            'email' => 'new.login@parish.test',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'role_ids' => [$this->staffRole->id],
            'active' => 1,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.person_id.0', fn ($message) => str_contains((string) $message, 'already has a login'));
    }
}
