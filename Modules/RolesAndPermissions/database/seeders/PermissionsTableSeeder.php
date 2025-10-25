<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\RolesAndPermissions\Models\Permission;
use Carbon\Carbon;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        $permissions = [
            // User Management Permissions
            ['name' => 'users.view', 'display_name' => 'View Users', 'description' => 'Can view user list and details', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.create', 'display_name' => 'Create Users', 'description' => 'Can create new users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Update Users', 'description' => 'Can update existing users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'description' => 'Can delete users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.activate', 'display_name' => 'Activate/Deactivate Users', 'description' => 'Can activate or deactivate users', 'module' => 'Authentication', 'category' => 'users'],
            
            // Tenant Management Permissions
            ['name' => 'tenants.view', 'display_name' => 'View Tenants', 'description' => 'Can view tenant list and details', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.create', 'display_name' => 'Create Tenants', 'description' => 'Can create new tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.update', 'display_name' => 'Update Tenants', 'description' => 'Can update existing tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.delete', 'display_name' => 'Delete Tenants', 'description' => 'Can delete tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.activate', 'display_name' => 'Activate/Deactivate Tenants', 'description' => 'Can activate or deactivate tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.statistics', 'display_name' => 'View Tenant Statistics', 'description' => 'Can view tenant statistics', 'module' => 'Tenants', 'category' => 'tenants'],
            
            // Role Management Permissions
            ['name' => 'roles.view', 'display_name' => 'View Roles', 'description' => 'Can view role list and details', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.create', 'display_name' => 'Create Roles', 'description' => 'Can create new custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.update', 'display_name' => 'Update Roles', 'description' => 'Can update existing custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.delete', 'display_name' => 'Delete Roles', 'description' => 'Can delete custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.activate', 'display_name' => 'Activate/Deactivate Roles', 'description' => 'Can activate or deactivate roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            
            // Permission Management Permissions
            ['name' => 'permissions.view', 'display_name' => 'View Permissions', 'description' => 'Can view permission list and details', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.create', 'display_name' => 'Create Permissions', 'description' => 'Can create new custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.update', 'display_name' => 'Update Permissions', 'description' => 'Can update existing custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.delete', 'display_name' => 'Delete Permissions', 'description' => 'Can delete custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.assign', 'display_name' => 'Assign Permissions', 'description' => 'Can assign permissions to roles and users', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::create(array_merge($permissionData, [
                'tenant_id' => null,
                'is_custom' => 0,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $this->command->info('✅ [RolesAndPermissions Module] System permissions created successfully!');
        $this->command->info('   - ' . count($permissions) . ' permissions seeded');
        $this->command->info('   - Categories: users, tenants, roles, permissions');
    }
}

