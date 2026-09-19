<?php

namespace Modules\Authentication\Services;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class PasswordAuthorizationService
{
    public const PERMISSION_CHANGE_SELF = 'users.password.change_self';

    public const PERMISSION_RESET_SUBORDINATES = 'users.password.reset_subordinates';

    public const PERMISSION_TENANT_ADMIN_RESET = 'tenant.admin_password.reset';

    public function canChangeOwnPassword(User $actor): bool
    {
        return $this->isEligibleActor($actor);
    }

    public function canResetPassword(User $actor, User $target): bool
    {
        if (! $this->isEligibleActor($actor)) {
            return false;
        }

        if ($actor->id === $target->id) {
            return false;
        }

        if ($target->trashed()) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return false;
        }

        $actorAuthority = $this->resolveAuthorityRole($actor);
        if ($actorAuthority === null || $actorAuthority->isSupportRole()) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        if (! $actor->hasPermission(self::PERMISSION_RESET_SUBORDINATES)) {
            return false;
        }

        $targetAuthority = $this->resolveAuthorityRole($target);
        if ($targetAuthority === null) {
            return false;
        }

        if ($this->isCrossDomainTenantAdminReset($actorAuthority, $target, $targetAuthority)) {
            return $this->canCrossDomainResetTenantAdministrator($actor, $target, $targetAuthority);
        }

        if ($actorAuthority->role_type !== $targetAuthority->role_type) {
            return false;
        }

        if ($actorAuthority->isTenantRole()) {
            if ($actor->tenant_id === null || $target->tenant_id === null) {
                return false;
            }

            if ((int) $actor->tenant_id !== (int) $target->tenant_id) {
                return false;
            }
        }

        return $this->isStrictlyLowerAuthority($actorAuthority, $targetAuthority);
    }

    public function canGrantPasswordPermission(User $actor, Role $targetRole, string $permissionName): bool
    {
        if (! in_array($permissionName, [
            self::PERMISSION_CHANGE_SELF,
            self::PERMISSION_RESET_SUBORDINATES,
            self::PERMISSION_TENANT_ADMIN_RESET,
        ], true)) {
            return true;
        }

        if ($permissionName === self::PERMISSION_CHANGE_SELF) {
            return false;
        }

        if ($targetRole->isSupportRole()) {
            return false;
        }

        if ($permissionName === self::PERMISSION_TENANT_ADMIN_RESET && ! $targetRole->isPlatformRole()) {
            return false;
        }

        if (! $actor->isSuperAdmin() && ! $actor->hasPermission($permissionName)) {
            return false;
        }

        $actorAuthority = $this->resolveAuthorityRole($actor);
        if ($actorAuthority === null || $actorAuthority->isSupportRole()) {
            return false;
        }

        if ($actorAuthority->role_type !== $targetRole->role_type) {
            return false;
        }

        if ($actorAuthority->isTenantRole() && (int) $targetRole->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }

        return $this->isStrictlyLowerAuthority($actorAuthority, $targetRole);
    }

    public function canGrantPasswordPermissionToUser(User $actor, User $target, string $permissionName): bool
    {
        if (! in_array($permissionName, [
            self::PERMISSION_CHANGE_SELF,
            self::PERMISSION_RESET_SUBORDINATES,
            self::PERMISSION_TENANT_ADMIN_RESET,
        ], true)) {
            return true;
        }

        if ($this->isSystemRequiredPermission($permissionName)) {
            return false;
        }

        if (! $actor->isSuperAdmin() && ! $actor->hasPermission($permissionName)) {
            return false;
        }

        $targetAuthority = $this->resolveAuthorityRole($target);
        $actorAuthority = $this->resolveAuthorityRole($actor);

        if ($targetAuthority === null || $actorAuthority === null || $actorAuthority->isSupportRole()) {
            return false;
        }

        if ($permissionName === self::PERMISSION_TENANT_ADMIN_RESET) {
            return $this->canCrossDomainResetTenantAdministrator($actor, $target, $targetAuthority);
        }

        if ($actorAuthority->role_type !== $targetAuthority->role_type) {
            return false;
        }

        if ($actorAuthority->isTenantRole()) {
            if ($actor->tenant_id === null || $target->tenant_id === null) {
                return false;
            }

            if ((int) $actor->tenant_id !== (int) $target->tenant_id) {
                return false;
            }
        }

        return $this->isStrictlyLowerAuthority($actorAuthority, $targetAuthority);
    }

    public function isSystemRequiredPermission(string $permissionName): bool
    {
        return $permissionName === self::PERMISSION_CHANGE_SELF;
    }

    public function resolveAuthorityRole(User $user): ?Role
    {
        $roles = $this->collectActiveRoles($user);

        if ($roles->isEmpty()) {
            return null;
        }

        $roleTypes = $roles->pluck('role_type')->unique()->filter()->values();
        if ($roleTypes->count() !== 1) {
            return null;
        }

        /** @var Role|null $strongest */
        $strongest = $roles->sortBy('level')->first();

        if ($strongest === null || $strongest->level === null || $strongest->role_type === null) {
            return null;
        }

        return $strongest;
    }

    private function collectActiveRoles(User $user)
    {
        $roles = collect();

        if ($user->relationLoaded('roles')) {
            $roles = $roles->merge($user->roles->filter(fn (Role $role) => $this->isActiveRole($role)));
        } else {
            $roles = $roles->merge($user->activeRoles()->get());
        }

        if ($user->role_id && $user->relationLoaded('role') && $user->role) {
            if ($this->isActiveRole($user->role)) {
                $roles->push($user->role);
            }
        } elseif ($user->role_id) {
            $legacyRole = Role::query()
                ->whereKey($user->role_id)
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->first();

            if ($legacyRole) {
                $roles->push($legacyRole);
            }
        }

        return $roles->unique('id')->values();
    }

    private function isActiveRole(Role $role): bool
    {
        return $role->active === 1 && $role->deleted_at === null;
    }

    private function isEligibleActor(User $actor): bool
    {
        return (int) $actor->active === 1 && ! $actor->trashed();
    }

    private function isStrictlyLowerAuthority(Role $actorRole, Role $targetRole): bool
    {
        if ($actorRole->role_type !== $targetRole->role_type) {
            return false;
        }

        return (int) $actorRole->level < (int) $targetRole->level;
    }

    private function isCrossDomainTenantAdminReset(Role $actorAuthority, User $target, Role $targetAuthority): bool
    {
        if (! $actorAuthority->isPlatformRole()) {
            return false;
        }

        if ($targetAuthority->isPlatformRole()) {
            return false;
        }

        return $target->isTenantAdmin() || $target->is_primary_admin;
    }

    private function canCrossDomainResetTenantAdministrator(User $actor, User $target, Role $targetAuthority): bool
    {
        if (! $actor->hasPermission(self::PERMISSION_RESET_SUBORDINATES)) {
            return false;
        }

        if (! $actor->hasPermission(self::PERMISSION_TENANT_ADMIN_RESET)) {
            return false;
        }

        if (! $target->isTenantAdmin() && ! $target->is_primary_admin) {
            return false;
        }

        if ($targetAuthority->isTenantRole() && ! $targetAuthority->isTenantAdministratorRole() && ! $target->is_primary_admin) {
            return false;
        }

        return true;
    }
}
