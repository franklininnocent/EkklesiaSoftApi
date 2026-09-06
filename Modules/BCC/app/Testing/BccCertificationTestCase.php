<?php

namespace Modules\BCC\Testing;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\BCC\Services\BccFamilyMembershipService;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

abstract class BccCertificationTestCase extends TestCase
{
    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function makeTenantUser(array $permissionNames): array
    {
        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);
        $role = Role::create([
            'name' => 'BCC Role '.uniqid(),
            'description' => 'Test',
            'level' => 3,
            'active' => 1,
            'tenant_id' => $tenant->id,
            'is_custom' => true,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
        ]);
        $user->syncRoles([$role->id]);

        $permissionIds = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Test permission',
                    'module' => 'BCC',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'bcc',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($permissionIds);
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return ['tenant' => $tenant, 'user' => $user->fresh(), 'role' => $role];
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function actingAsTenantWith(array $permissions): array
    {
        $context = $this->makeTenantUser($permissions);
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * @return array{bcc: BCC, families: list<Family>, memberships: list<BccFamilyMembership>}
     */
    protected function seedBccWithFamilies(Tenant $tenant, int $familyCount = 2): array
    {
        $bcc = BCC::factory()->active()->create(['tenant_id' => $tenant->id]);
        $service = app(BccFamilyMembershipService::class);
        $families = [];
        $memberships = [];

        foreach (range(1, $familyCount) as $_) {
            $family = Family::factory()->create([
                'tenant_id' => $tenant->id,
                'status' => 'active',
            ]);
            $service->assignFamilies((int) $tenant->id, $bcc->id, [$family->id]);
            $families[] = $family->fresh();
            $memberships[] = BccFamilyMembership::query()
                ->where('bcc_id', $bcc->id)
                ->where('family_id', $family->id)
                ->where('is_current', true)
                ->firstOrFail();
        }

        return [
            'bcc' => $bcc->fresh(),
            'families' => $families,
            'memberships' => $memberships,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotBcc(BCC $bcc): array
    {
        $bcc->refresh();

        return [
            'id' => $bcc->id,
            'tenant_id' => $bcc->tenant_id,
            'name' => $bcc->name,
            'status' => $bcc->status,
            'deleted_at' => $bcc->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotMembership(BccFamilyMembership $membership): array
    {
        $membership->refresh();

        return [
            'id' => $membership->id,
            'bcc_id' => $membership->bcc_id,
            'family_id' => $membership->family_id,
            'is_current' => $membership->is_current,
            'status' => $membership->status,
            'exit_date' => $membership->exit_date,
            'deleted_at' => $membership->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validBccPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Certification BCC',
            'description' => 'Test community',
            'meeting_day' => 'sunday',
            'meeting_time' => '10:00',
            'meeting_frequency' => 'Weekly',
            'status' => 'active',
        ], $overrides);
    }
}
