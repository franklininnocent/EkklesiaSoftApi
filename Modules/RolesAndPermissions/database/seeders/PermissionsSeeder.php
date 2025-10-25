<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\RolesAndPermissions\Models\Permission;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = $this->getStandardPermissions();

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']], // Check if permission exists by name
                $permission
            );
        }

        $this->command->info('✅ Standard permissions seeded successfully!');
        $this->command->info('Total permissions: ' . count($permissions));
    }

    /**
     * Get all standard permissions organized by module
     */
    private function getStandardPermissions(): array
    {
        $modules = [
            'Dashboard' => [
                'view' => 'View dashboard and analytics',
                'export' => 'Export dashboard reports',
            ],
            'Tenants' => [
                'view' => 'View tenant list and details',
                'create' => 'Create new tenants',
                'update' => 'Update tenant information',
                'delete' => 'Delete tenants',
                'download' => 'Download tenant data',
                'print' => 'Print tenant reports',
            ],
            'Users' => [
                'view' => 'View user list and details',
                'create' => 'Create new users',
                'update' => 'Update user information',
                'delete' => 'Delete users',
                'download' => 'Download user data',
                'print' => 'Print user reports',
                'activate' => 'Activate/deactivate users',
                'reset_password' => 'Reset user passwords',
            ],
            'Roles' => [
                'view' => 'View roles list and details',
                'create' => 'Create new roles',
                'update' => 'Update role information',
                'delete' => 'Delete roles',
                'download' => 'Download roles data',
                'print' => 'Print roles reports',
                'assign' => 'Assign roles to users',
            ],
            'Permissions' => [
                'view' => 'View permissions list',
                'create' => 'Create custom permissions',
                'update' => 'Update permission information',
                'delete' => 'Delete custom permissions',
                'assign' => 'Assign permissions to roles/users',
            ],
            'Settings' => [
                'view' => 'View system settings',
                'update' => 'Update system settings',
                'manage_general' => 'Manage general settings',
                'manage_security' => 'Manage security settings',
                'manage_email' => 'Manage email settings',
                'manage_notifications' => 'Manage notification settings',
            ],
            'Reports' => [
                'view' => 'View reports',
                'create' => 'Create custom reports',
                'download' => 'Download reports',
                'print' => 'Print reports',
                'schedule' => 'Schedule automated reports',
            ],
            'Audit' => [
                'view' => 'View audit logs',
                'download' => 'Download audit logs',
                'print' => 'Print audit logs',
                'delete' => 'Delete old audit logs',
            ],
            'Files' => [
                'view' => 'View files',
                'upload' => 'Upload files',
                'download' => 'Download files',
                'delete' => 'Delete files',
                'manage' => 'Manage file storage',
            ],
            'Notifications' => [
                'view' => 'View notifications',
                'create' => 'Send notifications',
                'delete' => 'Delete notifications',
            ],
        ];

        $permissions = [];
        $baseActions = ['create', 'view', 'update', 'delete', 'download', 'print'];

        foreach ($modules as $module => $actions) {
            foreach ($actions as $action => $description) {
                $permissions[] = [
                    'name' => strtolower($module) . '.' . $action,
                    'display_name' => ucfirst($action) . ' ' . $module,
                    'description' => $description,
                    'module' => $module,
                    'category' => $this->getCategoryForAction($action),
                    'tenant_id' => null, // System-wide permission
                    'is_custom' => false, // Standard permission
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        return $permissions;
    }

    /**
     * Get category for an action
     */
    private function getCategoryForAction(string $action): string
    {
        $categories = [
            'view' => 'Read',
            'create' => 'Write',
            'update' => 'Write',
            'delete' => 'Delete',
            'download' => 'Export',
            'print' => 'Export',
            'export' => 'Export',
            'upload' => 'Write',
            'activate' => 'Manage',
            'assign' => 'Manage',
            'reset_password' => 'Manage',
            'schedule' => 'Manage',
            'manage' => 'Manage',
            'manage_general' => 'Manage',
            'manage_security' => 'Manage',
            'manage_email' => 'Manage',
            'manage_notifications' => 'Manage',
        ];

        return $categories[$action] ?? 'Other';
    }
}

