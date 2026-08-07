<?php

namespace Modules\MinistriesAssociations\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class MinistriesAssociationsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'ministries.view',
                'display_name' => 'View Ministries & Associations',
                'description' => 'View organizations and ministry data',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.create',
                'display_name' => 'Create Organizations',
                'description' => 'Create ministries and associations',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.edit',
                'display_name' => 'Edit Organizations',
                'description' => 'Update organization profiles and status',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.delete',
                'display_name' => 'Delete Organizations',
                'description' => 'Archive and restore organizations',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.manage_members',
                'display_name' => 'Manage Ministry Members',
                'description' => 'Enroll members, manage guests, and parishioner lookup',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.manage_leadership',
                'display_name' => 'Manage Ministry Leadership',
                'description' => 'Assign, terminate, and hand over leadership terms',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
            ],
            [
                'name' => 'ministries.configure',
                'display_name' => 'Configure Ministries Taxonomies',
                'description' => 'Manage categories, types, positions, and seed defaults',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries',
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

        $this->assignMinistriesPermissionsToDefaultRoles();

        if ($this->command !== null) {
            $this->command->info('✅ Ministries & Associations permissions seeded (7 permissions).');
        }
    }

    private function assignMinistriesPermissionsToDefaultRoles(): void
    {
        $permissionIds = Permission::query()
            ->where('active', 1)
            ->whereIn('name', [
                'ministries.view',
                'ministries.create',
                'ministries.edit',
                'ministries.delete',
                'ministries.manage_members',
                'ministries.manage_leadership',
                'ministries.configure',
            ])
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        $roleNames = ['Administrator', 'Parish Priest', 'Church Pastor'];

        Role::query()
            ->whereNotNull('tenant_id')
            ->whereIn('name', $roleNames)
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                $role->clearUsersPermissionCache();
            });
    }
}
