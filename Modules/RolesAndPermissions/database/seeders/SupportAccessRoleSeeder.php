<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Assign Support Access platform permissions to SuperAdmin / EkklesiaAdmin.
 */
class SupportAccessRoleSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = Permission::query()
            ->where('module', 'SupportAccess')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            $this->command?->warn('No SupportAccess permissions found. Run SupportAccessPermissionSeeder first.');

            return;
        }

        $roles = Role::query()
            ->whereIn('name', ['SuperAdmin', 'EkklesiaAdmin'])
            ->get();

        foreach ($roles as $role) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $this->command?->info('✅ Support Access permissions assigned to platform admin roles');
    }
}
