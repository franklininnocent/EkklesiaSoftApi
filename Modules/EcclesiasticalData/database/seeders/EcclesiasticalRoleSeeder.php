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

        $viewAuditId = Permission::query()
            ->where('name', 'bishops.view_audit')
            ->value('id');

        $superAdmin = Role::query()->where('name', Role::SUPER_ADMIN)->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching($allIds);
            $superAdmin->clearUsersPermissionCache();
        }

        $ekklesiaAdmin = Role::query()->where('name', Role::EKKLESIA_ADMIN)->first();
        if ($ekklesiaAdmin) {
            $ekklesiaIds = $viewAuditId
                ? $allIds->reject(fn ($id) => (int) $id === (int) $viewAuditId)->values()
                : $allIds;
            $ekklesiaAdmin->permissions()->syncWithoutDetaching($ekklesiaIds);
            if ($viewAuditId) {
                DB::table('permission_role')
                    ->where('role_id', $ekklesiaAdmin->id)
                    ->where('permission_id', $viewAuditId)
                    ->delete();
            }
            $ekklesiaAdmin->clearUsersPermissionCache();
        }

        $readOnlyIds = Permission::query()
            ->where('module', 'EcclesiasticalData')
            ->whereIn('name', ['bishops.view', 'dioceses.view'])
            ->pluck('id');

        $reviewerIds = Permission::query()
            ->where('module', 'EcclesiasticalData')
            ->whereIn('name', [
                'bishops.view',
                'dioceses.view',
                'bishops.review_requests',
                'bishops.request_clarification',
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

            if ($viewAuditId) {
                DB::table('permission_role')
                    ->where('role_id', $role->id)
                    ->where('permission_id', $viewAuditId)
                    ->delete();
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
