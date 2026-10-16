<?php

namespace Modules\Sacraments\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Seed Sacraments Module Permissions into the shared permissions catalog.
 */
class SacramentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            [
                'name' => 'sacraments.view',
                'display_name' => 'View Sacraments',
                'description' => 'View sacramental records',
            ],
            [
                'name' => 'sacraments.create',
                'display_name' => 'Create Sacraments',
                'description' => 'Create new sacramental records',
            ],
            [
                'name' => 'sacraments.edit',
                'display_name' => 'Edit Sacraments',
                'description' => 'Edit sacramental metadata / legacy fields',
            ],
            [
                'name' => 'sacraments.correct',
                'display_name' => 'Correct Sacraments',
                'description' => 'Post-registration correction with reason and audit',
            ],
            [
                'name' => 'sacraments.void',
                'display_name' => 'Void Sacraments',
                'description' => 'Void sacramental records (business invalid)',
            ],
            [
                'name' => 'sacraments.delete',
                'display_name' => 'Delete Sacraments',
                'description' => 'Soft-delete sacramental records',
            ],
            [
                'name' => 'sacraments.restore',
                'display_name' => 'Restore Sacraments',
                'description' => 'Restore soft-deleted records (does not unvoid)',
            ],
            [
                'name' => 'sacraments.export',
                'display_name' => 'Export Sacraments',
                'description' => 'Export sacramental records',
            ],
            [
                'name' => 'sacraments.view_restricted',
                'display_name' => 'View Restricted Sacraments',
                'description' => 'View and create restricted sacramental records (e.g. Reconciliation)',
            ],
            [
                'name' => 'certificate.generate',
                'display_name' => 'Generate Certificates',
                'description' => 'Preview and generate official sacramental certificates',
            ],
            [
                'name' => 'certificate.download',
                'display_name' => 'Download Certificates',
                'description' => 'Download issued sacramental certificates',
            ],
            [
                'name' => 'certificate.reissue',
                'display_name' => 'Reissue Certificates',
                'description' => 'Reissue / supersede sacramental certificates',
            ],
            [
                'name' => 'sacraments.migration.view',
                'display_name' => 'View Sacrament Migration Queue',
                'description' => 'View migration resolutions and migration report',
            ],
            [
                'name' => 'sacraments.migration.resolve',
                'display_name' => 'Resolve Sacrament Migration',
                'description' => 'Resolve unresolved participants and run backfill',
            ],
            [
                'name' => 'sacraments.settings.view',
                'display_name' => 'View Sacrament Settings',
                'description' => 'View which sacrament types are available for this church',
            ],
            [
                'name' => 'sacraments.settings.manage',
                'display_name' => 'Manage Sacrament Settings',
                'description' => 'Activate or deactivate sacrament types for this church',
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'module' => 'Sacraments',
                    'category' => 'sacraments',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                ])
            );

            $this->command?->info("Seeded permission: {$permission['name']}");
        }

        $this->assignSettingsPermissionsToAdministrators();
    }

    private function assignSettingsPermissionsToAdministrators(): void
    {
        $permissionIds = Permission::query()
            ->where('active', 1)
            ->whereIn('name', [
                'sacraments.settings.view',
                'sacraments.settings.manage',
            ])
            ->pluck('id')
            ->all();

        if ($permissionIds === []) {
            return;
        }

        Role::query()
            ->whereNotNull('tenant_id')
            ->where('name', 'Administrator')
            ->each(function (Role $role) use ($permissionIds): void {
                $role->permissions()->syncWithoutDetaching($permissionIds);
                if (method_exists($role, 'clearUsersPermissionCache')) {
                    $role->clearUsersPermissionCache();
                }
            });
    }
}
