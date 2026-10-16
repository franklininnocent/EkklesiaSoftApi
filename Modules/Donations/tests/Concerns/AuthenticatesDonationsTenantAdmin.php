<?php

namespace Modules\Donations\Tests\Concerns;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;

trait AuthenticatesDonationsTenantAdmin
{
    protected Tenant $tenant;

    protected User $tenantAdminUser;

    protected Role $tenantAdminRole;

    protected function setUpDonationsTenantAdmin(): void
    {
        $this->tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
        ]);

        $this->tenantAdminRole = Role::create([
            'name' => Role::TENANT_ADMINISTRATOR,
            'description' => 'Tenant Administrator',
            'level' => 1,
            'active' => 1,
            'tenant_id' => $this->tenant->id,
            'is_custom' => false,
            'role_type' => Role::ROLE_TYPE_TENANT,
        ]);

        $this->tenantAdminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);
        $this->tenantAdminUser->syncRoles([$this->tenantAdminRole->id]);

        $permissionNames = [
            'donations.view',
            'donations.manage',
            'donations.collect',
            'donations.reverse',
            'donations.refund',
            'donations.reports',
            'donations.approvals',
            'donations.notifications',
            'church.settings.edit',
        ];

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
        $this->tenantAdminRole->permissions()->syncWithoutDetaching($permissionIds);

        Passport::actingAs($this->tenantAdminUser);
    }
}
