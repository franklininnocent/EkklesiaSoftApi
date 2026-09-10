<?php

namespace Modules\EcclesiasticalData\Tests\Support;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;

trait CreatesEkklesiaTestUser
{
    /**
     * @param  list<string>  $permissions
     */
    protected function createEkklesiaUser(array $permissions, string $roleName = Role::EKKLESIA_ADMIN): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName, 'tenant_id' => null],
            [
                'description' => 'Ekklesia test role',
                'level' => Role::LEVEL_EKKLESIA_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $permissionIds = [];
        foreach ($permissions as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Bishop create modal test permission',
                    'module' => 'EcclesiasticalData',
                    'scope' => Permission::SCOPE_PLATFORM,
                    'category' => 'bishops',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'tenant_id' => null,
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $user->clearPermissionsCache();

        return $user->fresh();
    }

    protected function createEkklesiaAdmin(): User
    {
        return $this->createEkklesiaUser([
            'bishops.view',
            'bishops.create',
            'bishops.update',
            'bishops.archive',
            'bishops.manage_appointments',
            'bishops.view_audit',
            'dioceses.view',
        ]);
    }
}
