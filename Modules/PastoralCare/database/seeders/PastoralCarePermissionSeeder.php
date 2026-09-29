<?php

namespace Modules\PastoralCare\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class PastoralCarePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'pastoral.care.view',
                'display_name' => 'View Pastoral Care',
                'description' => 'View visit requests and pastoral workflow',
            ],
            [
                'name' => 'pastoral.care.create',
                'display_name' => 'Request a Visit',
                'description' => 'Create pastoral visit requests for a family',
            ],
            [
                'name' => 'pastoral.care.assign',
                'display_name' => 'Assign Pastoral Follow-ups',
                'description' => 'Assign visit requests to pastoral staff',
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'module' => 'PastoralCare',
                    'category' => 'pastoral',
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
