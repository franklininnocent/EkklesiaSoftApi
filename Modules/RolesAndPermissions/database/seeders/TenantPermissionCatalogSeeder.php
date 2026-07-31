<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;

class TenantPermissionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Church settings
            ['name' => 'church.settings.view', 'display_name' => 'View Church Settings', 'description' => 'View church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.create', 'display_name' => 'Create Church Settings', 'description' => 'Create church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.edit', 'display_name' => 'Edit Church Settings', 'description' => 'Edit church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],
            ['name' => 'church.settings.delete', 'display_name' => 'Delete Church Settings', 'description' => 'Delete church settings', 'module' => 'ChurchSettings', 'category' => 'settings'],

            // Members
            ['name' => 'members.view', 'display_name' => 'View Members', 'description' => 'View members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.create', 'display_name' => 'Create Members', 'description' => 'Create members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.edit', 'display_name' => 'Edit Members', 'description' => 'Edit members', 'module' => 'Members', 'category' => 'members'],
            ['name' => 'members.delete', 'display_name' => 'Delete Members', 'description' => 'Delete members', 'module' => 'Members', 'category' => 'members'],

            // Families
            ['name' => 'families.view', 'display_name' => 'View Families', 'description' => 'View families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.create', 'display_name' => 'Create Families', 'description' => 'Create families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.edit', 'display_name' => 'Edit Families', 'description' => 'Edit families', 'module' => 'Families', 'category' => 'families'],
            ['name' => 'families.delete', 'display_name' => 'Delete Families', 'description' => 'Delete families', 'module' => 'Families', 'category' => 'families'],

            // Events
            ['name' => 'events.view', 'display_name' => 'View Events', 'description' => 'View events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.create', 'display_name' => 'Create Events', 'description' => 'Create events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.edit', 'display_name' => 'Edit Events', 'description' => 'Edit events', 'module' => 'Events', 'category' => 'events'],
            ['name' => 'events.delete', 'display_name' => 'Delete Events', 'description' => 'Delete events', 'module' => 'Events', 'category' => 'events'],

            // Attendance
            ['name' => 'attendance.view', 'display_name' => 'View Attendance', 'description' => 'View attendance', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.create', 'display_name' => 'Create Attendance', 'description' => 'Create attendance records', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.edit', 'display_name' => 'Edit Attendance', 'description' => 'Edit attendance records', 'module' => 'Attendance', 'category' => 'attendance'],
            ['name' => 'attendance.delete', 'display_name' => 'Delete Attendance', 'description' => 'Delete attendance records', 'module' => 'Attendance', 'category' => 'attendance'],

            // Donations
            ['name' => 'donations.view', 'display_name' => 'View Donations', 'description' => 'View donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.create', 'display_name' => 'Create Donations', 'description' => 'Create donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.edit', 'display_name' => 'Edit Donations', 'description' => 'Edit donations', 'module' => 'Donations', 'category' => 'donations'],
            ['name' => 'donations.delete', 'display_name' => 'Delete Donations', 'description' => 'Delete donations', 'module' => 'Donations', 'category' => 'donations'],

            // Reports
            ['name' => 'reports.view', 'display_name' => 'View Reports', 'description' => 'View reports', 'module' => 'Reports', 'category' => 'reports'],
            ['name' => 'reports.export', 'display_name' => 'Export Reports', 'description' => 'Export reports', 'module' => 'Reports', 'category' => 'reports'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                array_merge($permission, [
                    'tenant_id' => null,
                    'is_custom' => false,
                    'scope' => Permission::SCOPE_TENANT,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
