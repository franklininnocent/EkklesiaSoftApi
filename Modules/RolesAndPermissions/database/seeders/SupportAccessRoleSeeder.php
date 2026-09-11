<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Assign Support Access platform permissions to SuperAdmin / EkklesiaAdmin
 * and seed a dedicated SupportAdmin role with the full support-ops bundle.
 */
class SupportAccessRoleSeeder extends Seeder
{
    /** @var list<string> */
    private const SUPPORT_ADMIN_PERMISSIONS = [
        'support.sessions.start',
        'support.sessions.end',
        'support.sessions.view',
        'support.sessions.readonly',
        'support.sessions.standard',
        'support.sessions.emergency',
        'support.sessions.approve',
        'support.grants.view',
        'support.audit.view',
    ];

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
            $this->syncPermissions($role, $permissionIds->all());
        }

        $supportAdmin = Role::query()->updateOrCreate(
            ['name' => 'SupportAdmin'],
            [
                'description' => 'Platform support operator — enter tenants, audit, and manage sessions',
                'level' => Role::LEVEL_EKKLESIA_MANAGER,
                'active' => 1,
                'tenant_id' => null,
                'is_custom' => 0,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );

        $supportAdminPermissionIds = Permission::query()
            ->whereIn('name', self::SUPPORT_ADMIN_PERMISSIONS)
            ->pluck('id')
            ->all();

        $this->syncPermissions($supportAdmin, $supportAdminPermissionIds);

        $this->command?->info('✅ Support Access permissions assigned to platform admin roles and SupportAdmin');
    }

    /**
     * @param  list<int|string>  $permissionIds
     */
    private function syncPermissions(Role $role, array $permissionIds): void
    {
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
}
