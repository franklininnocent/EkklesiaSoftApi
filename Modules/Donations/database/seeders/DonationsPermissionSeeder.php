<?php

namespace Modules\Donations\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class DonationsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'donations.view', 'display_name' => 'View Donations', 'description' => 'View donations data', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.create', 'display_name' => 'Create Donations', 'description' => 'Create donations records', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.edit', 'display_name' => 'Edit Donations', 'description' => 'Edit donations records', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.delete', 'display_name' => 'Delete Donations', 'description' => 'Delete donations records', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.manage', 'display_name' => 'Manage Donations Setup', 'description' => 'Manage funds, plans, and projects', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.collect', 'display_name' => 'Collect Donations', 'description' => 'Create payments and allocations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.reverse', 'display_name' => 'Reverse Donation Payments', 'description' => 'Reverse posted payments', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.refund', 'display_name' => 'Request Donation Refunds', 'description' => 'Request and process refunds', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.export', 'display_name' => 'Export Donations', 'description' => 'Export donation datasets', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.approve', 'display_name' => 'Approve Donations Actions', 'description' => 'Approve donation financial actions', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.reports', 'display_name' => 'Access Donation Reports', 'description' => 'View and export donations reports', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.approvals', 'display_name' => 'Approve Donation Actions', 'description' => 'Approve reversals and refunds', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.notifications', 'display_name' => 'Manage Donation Notifications', 'description' => 'Manage reminders and receipt delivery', 'module' => 'Donations', 'category' => 'donations'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                ])
            );
        }

        $this->assignDonationsPermissionsToDefaultRoles();
    }

    private function assignDonationsPermissionsToDefaultRoles(): void
    {
        $donationPermissionIds = Permission::query()
            ->where('active', 1)
            ->where('name', 'like', 'donations.%')
            ->pluck('id')
            ->all();

        if ($donationPermissionIds === []) {
            return;
        }

        $roleNames = ['Administrator', 'Parish Priest', 'Church Pastor'];

        Role::query()
            ->whereNotNull('tenant_id')
            ->whereIn('name', $roleNames)
            ->each(function (Role $role) use ($donationPermissionIds): void {
                $role->permissions()->syncWithoutDetaching($donationPermissionIds);
                $role->clearUsersPermissionCache();
            });
    }
}
