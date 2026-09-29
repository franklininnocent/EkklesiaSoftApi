<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class ChurchLeadershipPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'church.leadership.roles.create',
                'display_name' => 'Create Custom Leadership Roles',
                'description' => 'Create tenant-specific leadership role titles for parish assignments',
                'module' => 'ChurchSettings',
                'category' => 'settings',
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
            ->where('name', 'church.leadership.roles.create')
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        Role::query()
            ->whereNotNull('tenant_id')
            ->where('name', Role::TENANT_ADMINISTRATOR)
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                $role->clearUsersPermissionCache();
            });
    }
}
