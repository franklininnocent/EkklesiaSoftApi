<?php

namespace Modules\Family\Testing;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

abstract class FamilyCertificationTestCase extends TestCase
{
    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function makeTenantAdmin(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->active()->create();

        $role = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);
        $this->grantFamilyPermissions($user);
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();

        return ['tenant' => $tenant, 'user' => $user->fresh(), 'role' => $role];
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    protected function makeStaffUser(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->active()->create();

        $user = User::factory()->tenantUser($tenant->id)->create();
        $this->grantFamilyPermissions($user);

        return ['tenant' => $tenant, 'user' => $user];
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    protected function makeParishionerUser(Tenant $tenant, Person $person): array
    {
        $user = User::factory()->tenantUser($tenant->id)->create([
            'person_id' => $person->id,
        ]);

        return ['tenant' => $tenant, 'user' => $user];
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function authenticateAsTenantAdmin(?Tenant $tenant = null): array
    {
        $context = $this->makeTenantAdmin($tenant);
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    protected function authenticateAsStaff(?Tenant $tenant = null): array
    {
        $context = $this->makeStaffUser($tenant);
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    protected function authenticateAsParishioner(Tenant $tenant, Person $person): array
    {
        $context = $this->makeParishionerUser($tenant, $person);
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{family: Family, head: FamilyMember, spouse: FamilyMember, child: FamilyMember, persons: array<string, Person>}
     */
    protected function seedHousehold(Tenant $tenant, array $opts = []): array
    {
        $family = Family::factory()->create([
            'tenant_id' => $tenant->id,
            'family_name' => $opts['family_name'] ?? 'Martinez Family',
            'address_line_1' => $opts['address_line_1'] ?? '100 Parish Lane',
            'city' => $opts['city'] ?? 'Springfield',
            'status' => 'active',
        ]);

        $head = FamilyMember::factory()->head()->active()->create([
            'family_id' => $family->id,
            'first_name' => $opts['head_first_name'] ?? 'Carlos',
            'last_name' => $opts['head_last_name'] ?? 'Martinez',
            'date_of_birth' => $opts['head_dob'] ?? '1975-06-15',
            'gender' => 'male',
            'phone' => $opts['phone'] ?? '+12025550100',
            'is_primary_contact' => true,
        ]);

        $spouse = FamilyMember::factory()->spouse()->active()->create([
            'family_id' => $family->id,
            'first_name' => $opts['spouse_first_name'] ?? 'Maria',
            'last_name' => $opts['spouse_last_name'] ?? 'Martinez',
            'date_of_birth' => '1978-03-20',
            'gender' => 'female',
        ]);

        $child = FamilyMember::factory()->child()->active()->create([
            'family_id' => $family->id,
            'first_name' => $opts['child_first_name'] ?? 'Ana',
            'last_name' => $opts['child_last_name'] ?? 'Martinez',
            'date_of_birth' => $opts['child_dob'] ?? '2010-01-10',
            'gender' => 'female',
        ]);

        $family->update(['head_of_family' => trim("{$head->first_name} {$head->last_name}")]);

        return [
            'family' => $family->fresh(),
            'head' => $head->fresh(),
            'spouse' => $spouse->fresh(),
            'child' => $child->fresh(),
            'persons' => [
                'head' => Person::find($head->person_id),
                'spouse' => Person::find($spouse->person_id),
                'child' => Person::find($child->person_id),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotFamily(Family $family): array
    {
        $family->refresh();

        return [
            'id' => $family->id,
            'tenant_id' => $family->tenant_id,
            'family_name' => $family->family_name,
            'address_line_1' => $family->address_line_1,
            'city' => $family->city,
            'status' => $family->status,
            'deleted_at' => $family->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotMember(FamilyMember $member): array
    {
        $member->refresh();

        return [
            'id' => $member->id,
            'family_id' => $member->family_id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'relationship_to_head' => $member->relationship_to_head,
            'status' => $member->status,
            'deleted_at' => $member->deleted_at,
        ];
    }

    /**
     * @param  list<string>  $names
     */
    protected function grantFamilyPermissions(User $user, array $names = []): void
    {
        $names = $names !== [] ? $names : [
            'families.view',
            'families.create',
            'families.edit',
            'families.delete',
        ];

        $ids = [];
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'module' => 'Families',
                    'category' => 'families',
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
        $user->clearRequestPermissionCache();
    }
}
