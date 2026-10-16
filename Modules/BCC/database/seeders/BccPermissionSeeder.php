<?php

namespace Modules\BCC\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class BccPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'bcc.view',
                'display_name' => 'View BCCs',
                'description' => 'View BCC dashboard, list, members, history, and audit',
                'module' => 'BCC',
                'category' => 'bcc',
            ],
            [
                'name' => 'bcc.create',
                'display_name' => 'Create BCCs',
                'description' => 'Create Basic Christian Communities',
                'module' => 'BCC',
                'category' => 'bcc',
            ],
            [
                'name' => 'bcc.edit',
                'display_name' => 'Edit BCCs',
                'description' => 'Update BCC profiles and status',
                'module' => 'BCC',
                'category' => 'bcc',
            ],
            [
                'name' => 'bcc.delete',
                'display_name' => 'Delete BCCs',
                'description' => 'Soft-delete BCCs and unassign families',
                'module' => 'BCC',
                'category' => 'bcc',
            ],
            [
                'name' => 'bcc.manage_members',
                'display_name' => 'Manage BCC Members',
                'description' => 'Assign and remove families from a BCC',
                'module' => 'BCC',
                'category' => 'bcc',
            ],
            [
                'name' => 'bcc.manage_leadership',
                'display_name' => 'Manage BCC Leadership',
                'description' => 'Assign, end, and hand over BCC leadership',
                'module' => 'BCC',
                'category' => 'bcc',
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

        $this->assignToDefaultRoles();
    }

    private function assignToDefaultRoles(): void
    {
        $permissionIds = Permission::query()
            ->where('active', 1)
            ->whereIn('name', [
                'bcc.view',
                'bcc.create',
                'bcc.edit',
                'bcc.delete',
                'bcc.manage_members',
                'bcc.manage_leadership',
            ])
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
