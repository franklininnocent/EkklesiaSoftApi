<?php

namespace Modules\RolesAndPermissions\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Services\PasswordAuthorizationService;
use Modules\RolesAndPermissions\Models\Permission;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();
        $passwordModule = 'Password & Account Security';

        $permissions = [
            ['name' => 'users.view', 'display_name' => 'View Users', 'description' => 'Can view user list and details', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.create', 'display_name' => 'Create Users', 'description' => 'Can create new users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Update Users', 'description' => 'Can update existing users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'description' => 'Can delete users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.activate', 'display_name' => 'Activate/Deactivate Users', 'description' => 'Can activate or deactivate users', 'module' => 'Authentication', 'category' => 'users'],
            ['name' => 'users.password.change_self', 'display_name' => 'Change Own Password', 'description' => 'Required security capability for self-service password change', 'module' => $passwordModule, 'category' => 'Manage', 'scope' => Permission::SCOPE_BOTH],
            ['name' => 'users.password.reset_subordinates', 'display_name' => 'Reset Lower-Level User Password', 'description' => 'Can reset passwords for strictly lower-level users in scope', 'module' => $passwordModule, 'category' => 'Manage', 'scope' => Permission::SCOPE_BOTH],
            ['name' => 'tenant.admin_password.reset', 'display_name' => 'Reset Tenant Administrator Password', 'description' => 'Platform capability to reset tenant administrator passwords', 'module' => $passwordModule, 'category' => 'Manage', 'scope' => Permission::SCOPE_PLATFORM],
            ['name' => 'password.recovery.requests.view', 'display_name' => 'View Password Recovery Requests', 'description' => 'Can view forgot password recovery requests awaiting approval', 'module' => $passwordModule, 'category' => 'Manage', 'scope' => Permission::SCOPE_BOTH],
            ['name' => 'password.recovery.requests.process', 'display_name' => 'Process Password Recovery Requests', 'description' => 'Can approve or reject forgot password recovery requests', 'module' => $passwordModule, 'category' => 'Manage', 'scope' => Permission::SCOPE_BOTH],
            ['name' => 'tenants.view', 'display_name' => 'View Tenants', 'description' => 'Can view tenant list and details', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.create', 'display_name' => 'Create Tenants', 'description' => 'Can create new tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.update', 'display_name' => 'Update Tenants', 'description' => 'Can update existing tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.delete', 'display_name' => 'Delete Tenants', 'description' => 'Can delete tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.activate', 'display_name' => 'Activate/Deactivate Tenants', 'description' => 'Can activate or deactivate tenants', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'tenants.statistics', 'display_name' => 'View Tenant Statistics', 'description' => 'Can view tenant statistics', 'module' => 'Tenants', 'category' => 'tenants'],
            ['name' => 'subscription.view', 'display_name' => 'View Subscription', 'description' => 'Can view own church subscription status', 'module' => 'Tenants', 'category' => 'subscription', 'scope' => Permission::SCOPE_TENANT],
            ['name' => 'roles.view', 'display_name' => 'View Roles', 'description' => 'Can view role list and details', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.create', 'display_name' => 'Create Roles', 'description' => 'Can create new custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.update', 'display_name' => 'Update Roles', 'description' => 'Can update existing custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.delete', 'display_name' => 'Delete Roles', 'description' => 'Can delete custom roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.activate', 'display_name' => 'Activate/Deactivate Roles', 'description' => 'Can activate or deactivate roles', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'roles.assign', 'display_name' => 'Assign Roles', 'description' => 'Can assign roles to users', 'module' => 'RolesAndPermissions', 'category' => 'roles'],
            ['name' => 'permissions.view', 'display_name' => 'View Permissions', 'description' => 'Can view permission list and details', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.create', 'display_name' => 'Create Permissions', 'description' => 'Can create new custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.update', 'display_name' => 'Update Permissions', 'description' => 'Can update existing custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.delete', 'display_name' => 'Delete Permissions', 'description' => 'Can delete custom permissions', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
            ['name' => 'permissions.assign', 'display_name' => 'Assign Permissions', 'description' => 'Can assign permissions to roles and users', 'module' => 'RolesAndPermissions', 'category' => 'permissions'],
        ];

        foreach ($permissions as $permissionData) {
            $scope = $permissionData['scope']
                ?? ($permissionData['module'] === 'Tenants'
                    ? Permission::SCOPE_PLATFORM
                    : Permission::SCOPE_TENANT);
            unset($permissionData['scope']);

            Permission::updateOrCreate(['name' => $permissionData['name']], array_merge($permissionData, [
                'tenant_id' => null,
                'is_custom' => 0,
                'scope' => $scope,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $changeSelf = Permission::query()->where('name', PasswordAuthorizationService::PERMISSION_CHANGE_SELF)->first();
        $resetSubordinates = Permission::query()->where('name', PasswordAuthorizationService::PERMISSION_RESET_SUBORDINATES)->first();
        $tenantAdminReset = Permission::query()->where('name', PasswordAuthorizationService::PERMISSION_TENANT_ADMIN_RESET)->first();

        if ($changeSelf) {
            Role::query()
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->each(function (Role $role) use ($changeSelf): void {
                    $role->givePermissionTo($changeSelf);
                });
        }

        if ($resetSubordinates) {
            Role::query()
                ->whereIn('name', [Role::EKKLESIA_ADMIN, Role::TENANT_ADMINISTRATOR])
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->each(function (Role $role) use ($resetSubordinates): void {
                    $role->givePermissionTo($resetSubordinates);
                });
        }

        if ($tenantAdminReset) {
            Role::query()
                ->where('name', Role::EKKLESIA_ADMIN)
                ->whereNull('tenant_id')
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->each(function (Role $role) use ($tenantAdminReset): void {
                    $role->givePermissionTo($tenantAdminReset);
                });
        }

        $recoveryView = Permission::query()->where('name', 'password.recovery.requests.view')->first();
        $recoveryProcess = Permission::query()->where('name', 'password.recovery.requests.process')->first();

        if ($recoveryView && $recoveryProcess) {
            Role::query()
                ->where('name', Role::TENANT_ADMINISTRATOR)
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->each(function (Role $role) use ($recoveryView, $recoveryProcess): void {
                    $role->givePermissionTo($recoveryView);
                    $role->givePermissionTo($recoveryProcess);
                });
        }

        $this->command->info('✅ [RolesAndPermissions Module] System permissions created successfully!');
        $this->command->info('   - '.count($permissions).' permissions seeded');
    }
}
