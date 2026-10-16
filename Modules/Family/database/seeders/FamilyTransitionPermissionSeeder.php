<?php

namespace Modules\Family\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class FamilyTransitionPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'families.bcc.relocate',
                'display_name' => 'Relocate Family BCC',
                'description' => 'Preview and execute BCC relocation for a family',
                'module' => 'Family',
                'category' => 'family',
            ],
            [
                'name' => 'families.marriage.transition',
                'display_name' => 'Marriage Household Transition',
                'description' => 'Create new household or join existing via marriage',
                'module' => 'Family',
                'category' => 'family',
            ],
            [
                'name' => 'families.history.view',
                'display_name' => 'View Household Transition History',
                'description' => 'View household and BCC transition history for families',
                'module' => 'Family',
                'category' => 'family',
            ],
            [
                'name' => 'families.history.correct',
                'display_name' => 'Correct Household History',
                'description' => 'Append administrative corrections to household history',
                'module' => 'Family',
                'category' => 'family',
            ],
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

        $permissionIds = Permission::query()
            ->where('active', 1)
            ->whereIn('name', array_column($permissions, 'name'))
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        Role::query()
            ->whereNotNull('tenant_id')
            ->whereIn('name', ['Administrator', 'Parish Priest', 'Church Pastor'])
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                if (method_exists($role, 'clearUsersPermissionCache')) {
                    $role->clearUsersPermissionCache();
                }
            });
    }
}
