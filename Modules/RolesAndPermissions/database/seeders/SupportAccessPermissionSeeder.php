<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Platform Support Access permissions (catalog only; assignment in Phase 1 roles).
 */
class SupportAccessPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $permissions = [
            [
                'name' => 'support.sessions.readonly',
                'display_name' => 'Support sessions (read only)',
                'description' => 'Enter a tenant in read-only support mode',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.standard',
                'display_name' => 'Support sessions (standard)',
                'description' => 'Enter a tenant in standard support mode',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.emergency',
                'display_name' => 'Support sessions (emergency)',
                'description' => 'Enter a tenant in emergency support mode',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.start',
                'display_name' => 'Start support sessions',
                'description' => 'Start a support session into a tenant',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.end',
                'display_name' => 'End support sessions',
                'description' => 'End own or monitored support sessions',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.view',
                'display_name' => 'View support sessions',
                'description' => 'View active and historical support sessions',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.sessions.approve',
                'display_name' => 'Approve emergency support',
                'description' => 'Approve or reject emergency support access requests (four-eyes)',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.grants.view',
                'display_name' => 'View support grants',
                'description' => 'View customer-granted support access windows',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.grants.manage',
                'display_name' => 'Manage support grants',
                'description' => 'Create and revoke customer-granted support access windows',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.audit.view',
                'display_name' => 'View support audit',
                'description' => 'View support session audit events',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
            [
                'name' => 'support.configuration.manage',
                'display_name' => 'Manage support configuration',
                'description' => 'Manage support session settings',
                'module' => 'SupportAccess',
                'category' => 'support',
            ],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(
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
        }

        $this->command?->info('✅ Support Access permissions seeded ('.count($permissions).')');
    }
}
