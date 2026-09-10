<?php

namespace Modules\EcclesiasticalData\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

class EcclesiasticalRoleSeeder extends Seeder
{
    public function run(): void
    {
        $allIds = Permission::query()
            ->where('module', 'EcclesiasticalData')
            ->pluck('id');

        if ($allIds->isEmpty()) {
            $this->command?->warn('No EcclesiasticalData permissions found. Run EcclesiasticalPermissionSeeder first.');

            return;
        }

        $adminRoles = Role::query()
            ->whereIn('name', [Role::SUPER_ADMIN, Role::EKKLESIA_ADMIN])
            ->get();

        foreach ($adminRoles as $role) {
            $role->permissions()->syncWithoutDetaching($allIds);
            $role->clearUsersPermissionCache();
        }

        $readOnlyIds = Permission::query()
            ->where('module', 'EcclesiasticalData')
            ->whereIn('name', ['bishops.view', 'dioceses.view', 'bishops.view_audit'])
            ->pluck('id');

        $reviewerIds = Permission::query()
            ->where('module', 'EcclesiasticalData')
            ->whereIn('name', [
                'bishops.view',
                'dioceses.view',
                'bishops.review_requests',
                'bishops.request_clarification',
                'bishops.view_audit',
            ])
            ->pluck('id');

        foreach ([Role::EKKLESIA_MANAGER => $reviewerIds, Role::EKKLESIA_USER => $readOnlyIds] as $roleName => $permissionIds) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            $role->clearUsersPermissionCache();
        }

        $tenantPermissionIds = Permission::query()
            ->whereIn('name', [
                'bishops.view',
                'bishops.submit_update_request',
                'bishops.view_own_requests',
            ])
            ->pluck('id');

        if ($tenantPermissionIds->isNotEmpty()) {
            Role::query()
                ->whereNotNull('tenant_id')
                ->where('name', Role::TENANT_ADMINISTRATOR)
                ->each(function (Role $role) use ($tenantPermissionIds): void {
                    $role->permissions()->syncWithoutDetaching($tenantPermissionIds);
                    $role->clearUsersPermissionCache();
                });
        }

        $this->command?->info('✅ Ecclesiastical permissions assigned to platform and tenant admin roles');
    }
}
