<?php

namespace Tests\Concerns;

use Laravel\Passport\Passport;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Tenant;

/**
 * Persona helpers for SaaS RBAC feature tests.
 *
 * Always attach a real Role via users.role_id + syncRoles(). Do not rely on
 * Tests\TestCase::actingAsSuperAdmin() / actingAsTenantAdmin() — those only
 * set user_type flags, and isSuperAdmin()/isTenantAdmin() check role names.
 */
trait ActsAsTenantRoles
{
    /**
     * @return list<string>
     */
    protected function staffPermissionNames(): array
    {
        return [
            'sacraments.view',
            'sacraments.create',
            'sacraments.edit',
            'donations.view',
            'donations.collect',
            'bcc.view',
            'bcc.create',
            'bcc.edit',
            'bcc.manage_members',
        ];
    }

    /**
     * @return list<string>
     */
    protected function parishAdminPermissionNames(): array
    {
        return array_values(array_unique(array_merge($this->staffPermissionNames(), [
            'sacraments.delete',
            'sacraments.void',
            'sacraments.restore',
            'sacraments.settings.view',
            'sacraments.settings.manage',
            'donations.reverse',
            'donations.refund',
            'donations.manage',
            'bcc.delete',
            'church.settings.edit',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',
            'permissions.view',
            'permissions.assign',
            'users.view',
        ])));
    }

    protected function makeOperationalTenant(?Tenant $tenant = null): Tenant
    {
        if ($tenant !== null) {
            return $tenant;
        }

        return Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->addYear(),
            'trial_ends_at' => now()->addYear(),
            'features' => ['donations', 'events', 'groups'],
        ]);
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function asSuperAdmin(): array
    {
        $role = Role::query()->firstOrCreate(
            [
                'name' => Role::SUPER_ADMIN,
                'tenant_id' => null,
            ],
            [
                'description' => 'Platform Super Admin',
                'level' => Role::LEVEL_SUPER_ADMIN,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_PLATFORM,
            ]
        );

        $user = User::factory()->superAdmin()->create([
            'role_id' => $role->id,
            'active' => 1,
        ]);
        $user->syncRoles([$role->id]);
        $this->refreshUserPermissionState($user);
        Passport::actingAs($user->fresh());

        return ['tenant' => null, 'user' => $user->fresh(), 'role' => $role];
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function asTenantAdmin(?Tenant $tenant = null): array
    {
        $context = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function asStaff(?Tenant $tenant = null): array
    {
        $context = $this->makeTenantPersona(
            $tenant,
            'Staff',
            $this->staffPermissionNames(),
            [
                'is_custom' => true,
                'level' => 3,
                'role_classification' => Role::CLASSIFICATION_CUSTOM,
            ]
        );
        Passport::actingAs($context['user']);

        return $context;
    }

    /**
     * Plan-facing alias. Use this from new tests; asStaff() is the canonical name
     * because Tests\TestCase already owns actingAsTenantAdmin().
     *
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function actingAsStaff(?Tenant $tenant = null): array
    {
        return $this->asStaff($tenant);
    }

    /**
     * @return array{tenant: Tenant, user: User, role: null}
     */
    protected function actingAsParishioner(Tenant $tenant, Person $person): array
    {
        return $this->asParishioner($tenant, $person);
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function actingAsDeactivatedUser(?Tenant $tenant = null): array
    {
        return $this->asDeactivatedUser($tenant);
    }

    /**
     * @return array{tenant: Tenant, user: User, role: null}
     */
    protected function asParishioner(Tenant $tenant, Person $person): array
    {
        $user = User::factory()->tenantUser($tenant->id)->create([
            'person_id' => $person->id,
            'active' => 1,
        ]);
        $this->refreshUserPermissionState($user);
        Passport::actingAs($user->fresh());

        return ['tenant' => $tenant, 'user' => $user->fresh(), 'role' => null];
    }

    /**
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function asDeactivatedUser(?Tenant $tenant = null): array
    {
        $context = $this->makeTenantPersona(
            $tenant,
            Role::TENANT_ADMINISTRATOR,
            $this->parishAdminPermissionNames(),
            [
                'is_custom' => false,
                'level' => 1,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );
        $context['user']->forceFill(['active' => 0])->save();
        $user = $context['user']->fresh();
        Passport::actingAs($user);

        return ['tenant' => $context['tenant'], 'user' => $user, 'role' => $context['role']];
    }

    protected function switchTo(User $user): void
    {
        Passport::actingAs($user->fresh());
    }

    protected function clearApiAuth(): void
    {
        auth()->forgetGuards();
    }

    /**
     * @param  list<string>  $names
     */
    protected function grantPermissions(Role $role, array $names): void
    {
        $permissionIds = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'RBAC 360 test permission',
                    'module' => $this->permissionModuleFor($name),
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => explode('.', $name)[0] ?? 'rbac',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->syncWithoutDetaching($permissionIds);
        if (method_exists($role, 'clearUsersPermissionCache')) {
            $role->clearUsersPermissionCache();
        }
    }

    /**
     * @param  array{is_custom: bool, level: int, role_classification?: string}  $roleAttrs
     * @param  list<string>  $permissionNames
     * @return array{tenant: Tenant, user: User, role: Role}
     */
    protected function makeTenantPersona(
        ?Tenant $tenant,
        string $roleName,
        array $permissionNames,
        array $roleAttrs
    ): array {
        $tenant = $this->makeOperationalTenant($tenant);

        $role = Role::query()->firstOrCreate(
            [
                'name' => $roleName,
                'tenant_id' => $tenant->id,
            ],
            [
                'description' => $roleName.' test role',
                'level' => $roleAttrs['level'],
                'active' => 1,
                'is_custom' => $roleAttrs['is_custom'],
                'role_type' => Role::ROLE_TYPE_TENANT,
                'role_classification' => $roleAttrs['role_classification'] ?? Role::CLASSIFICATION_CUSTOM,
            ]
        );

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'active' => 1,
            'is_primary_admin' => $roleName === Role::TENANT_ADMINISTRATOR,
        ]);
        $user->syncRoles([$role->id]);
        $this->grantPermissions($role, $permissionNames);
        $this->refreshUserPermissionState($user);

        return ['tenant' => $tenant, 'user' => $user->fresh(), 'role' => $role];
    }

    protected function refreshUserPermissionState(User $user): void
    {
        $user->clearRequestPermissionCache();
        $user->clearPermissionsCache();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        $user->unsetRelation('role');
    }

    protected function permissionModuleFor(string $name): string
    {
        $prefix = explode('.', $name)[0] ?? 'rbac';

        return match ($prefix) {
            'sacraments', 'certificate' => 'Sacraments',
            'donations' => 'Donations',
            'bcc' => 'BCC',
            'church' => 'ChurchSettings',
            'roles', 'permissions' => 'RolesAndPermissions',
            'users' => 'Authentication',
            'families' => 'Families',
            'members' => 'Members',
            default => 'RBAC',
        };
    }
}
