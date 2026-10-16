<?php

namespace Modules\Donations\Testing;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Models\Fund;
use Modules\Family\Models\Family;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;
use Tests\TestCase;

abstract class DonationsCertificationTestCase extends TestCase
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
            'name' => 'Donations Role '.uniqid(),
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
                    'module' => 'Donations',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => 'donations',
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

    protected function actingAsTenantWith(array $permissions): array
    {
        $context = $this->makeTenantUser($permissions);
        Passport::actingAs($context['user']);

        return $context;
    }

    protected function seedDue(int $tenantId, ?Family $family = null, string $amount = '1000.00'): array
    {
        $fund = Fund::create([
            'tenant_id' => $tenantId,
            'name' => 'General Fund',
            'code' => 'GEN-'.substr(uniqid(), -6),
            'status' => 'active',
        ]);
        $plan = ContributionPlan::create([
            'tenant_id' => $tenantId,
            'fund_id' => $fund->id,
            'name' => 'Monthly Tithe',
            'code' => 'TITHE-'.substr(uniqid(), -6),
            'frequency' => 'monthly',
            'default_amount' => $amount,
            'status' => 'active',
        ]);
        $family ??= Family::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']);
        $due = ContributionDue::create([
            'tenant_id' => $tenantId,
            'family_id' => $family->id,
            'plan_id' => $plan->id,
            'period_label' => '2026-09-'.substr(uniqid(), -4),
            'due_date' => now()->toDateString(),
            'amount_due' => $amount,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        return compact('fund', 'plan', 'family', 'due');
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentPayload(Family $family, string $amount = '100.00', array $overrides = []): array
    {
        return array_merge([
            'family_id' => $family->id,
            'payer_name' => $family->family_name ?? 'Test Payer',
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'cash',
        ], $overrides);
    }
}
