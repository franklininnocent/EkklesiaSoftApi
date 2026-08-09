<?php

namespace Modules\MinistriesAssociations\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Platform (Ekklesia) permissions for Ministries Insights — SCOPE_PLATFORM only.
 */
class AdminMinistriesPlatformPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $permissions = [
            [
                'name' => 'ministries.platform.overview',
                'display_name' => 'Ministries Insights overview',
                'description' => 'View platform Ministries & Associations overview, funnel, and attention',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.tenants',
                'display_name' => 'Ministries Insights tenants',
                'description' => 'View tenant adoption table and tenant detail analytics',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.organizations',
                'display_name' => 'Ministries Insights organizations',
                'description' => 'View cross-tenant organization analytics (read-only)',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.analytics',
                'display_name' => 'Ministries Insights analytics',
                'description' => 'View usage, feature adoption, and trends',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.audit',
                'display_name' => 'Ministries Insights audit',
                'description' => 'View cross-tenant Ministries activity and governance audit',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.reports',
                'display_name' => 'Ministries Insights reports',
                'description' => 'View Ministries Insights reports',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
            [
                'name' => 'ministries.platform.export',
                'display_name' => 'Ministries Insights export',
                'description' => 'Export Ministries Insights datasets (CSV)',
                'module' => 'MinistriesAssociations',
                'category' => 'ministries_platform',
            ],
        ];

        $permissionIds = [];
        foreach ($permissions as $permissionData) {
            $permission = Permission::updateOrCreate(
                ['name' => $permissionData['name']],
                array_merge($permissionData, [
                    'tenant_id' => null,
                    'is_custom' => 0,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
            $permissionIds[] = $permission->id;
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
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
            $role->clearUsersPermissionCache();
        }

        $this->command?->info('✅ Ministries Insights platform permissions seeded ('.count($permissions).')');
    }
}
