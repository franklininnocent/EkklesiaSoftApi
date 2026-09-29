<?php

namespace Modules\ApplicationAccess\Support;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Idempotent permission catalog + role assignment (migrate + seed).
 */
final class ApplicationAccessPermissionCatalog
{
    /** @var list<string> */
    public const ALL_PERMISSIONS = [
        'application_access.view',
        'application_access.investigate',
        'application_access.revoke_session',
        'application_access.block_ip',
        'application_access.unblock_ip',
        'application_access.export',
        'application_access.manage',
    ];

    /** @var list<string> */
    public const EKKLESIA_ADMIN_DEFAULT = [
        'application_access.view',
        'application_access.investigate',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function permissionDefinitions(): array
    {
        return [
            'application_access.view' => [
                'display_name' => 'View Application Access',
                'description' => 'View application access dashboard and lists',
                'category' => 'application_access',
            ],
            'application_access.investigate' => [
                'display_name' => 'Investigate Application Access',
                'description' => 'Open investigation views and unmasked identifiers',
                'category' => 'application_access',
            ],
            'application_access.revoke_session' => [
                'display_name' => 'Revoke application sessions',
                'description' => 'Sign out users by revoking Passport sessions',
                'category' => 'application_access',
            ],
            'application_access.block_ip' => [
                'display_name' => 'Block IP addresses',
                'description' => 'Create application-level IP block rules',
                'category' => 'application_access',
            ],
            'application_access.unblock_ip' => [
                'display_name' => 'Unblock IP addresses',
                'description' => 'Revoke application-level IP block rules',
                'category' => 'application_access',
            ],
            'application_access.export' => [
                'display_name' => 'Export Application Access data',
                'description' => 'Export security telemetry datasets',
                'category' => 'application_access',
            ],
            'application_access.manage' => [
                'display_name' => 'Manage Application Access',
                'description' => 'Manage retention and security configuration',
                'category' => 'application_access',
            ],
        ];
    }

    public static function syncPermissionsAndRoles(): void
    {
        $now = now();
        $permissionIds = [];

        foreach (self::permissionDefinitions() as $name => $meta) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $meta['display_name'],
                    'description' => $meta['description'],
                    'module' => 'ApplicationAccess',
                    'category' => $meta['category'],
                    'tenant_id' => null,
                    'is_custom' => 0,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            $permissionIds[$name] = $permission->id;
        }

        $superAdmin = Role::query()->where('name', Role::SUPER_ADMIN)->first();
        if ($superAdmin) {
            self::syncRolePermissions($superAdmin, array_values($permissionIds));
        }

        $ekklesiaAdmin = Role::query()->where('name', Role::EKKLESIA_ADMIN)->first();
        if ($ekklesiaAdmin) {
            $adminIds = array_map(
                fn (string $name) => $permissionIds[$name],
                self::EKKLESIA_ADMIN_DEFAULT
            );
            self::syncRolePermissions($ekklesiaAdmin, $adminIds);
        }
    }

    /**
     * @param  list<int|string>  $permissionIds
     */
    private static function syncRolePermissions(Role $role, array $permissionIds): void
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
