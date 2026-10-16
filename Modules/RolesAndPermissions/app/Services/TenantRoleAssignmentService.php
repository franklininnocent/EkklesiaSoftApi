<?php

namespace Modules\RolesAndPermissions\Services;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class TenantRoleAssignmentService
{
    public function __construct(private PermissionAuditService $auditService)
    {
    }

    public function getUserRoles(User $actor, int $userId): User
    {
        $this->ensureTenantActor($actor);

        return User::where('tenant_id', $actor->tenant_id)
            ->with(['roles' => function ($query) {
                $query->where('roles.active', 1)
                    ->whereNull('roles.deleted_at');
            }])
            ->findOrFail($userId);
    }

    public function syncUserRoles(User $actor, User $user, array $roleIds): User
    {
        $this->ensureTenantActor($actor);
        $this->assertTenantOwnedUser($actor, $user);

        $roles = Role::whereIn('id', $roleIds)
            ->where('tenant_id', $actor->tenant_id)
            ->where('role_type', Role::ROLE_TYPE_TENANT)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->get();

        if ($roles->count() !== count($roleIds)) {
            throw new \RuntimeException('One or more selected roles are invalid for your tenant.', 422);
        }

        $tenantAdminRoleId = Role::where('tenant_id', $actor->tenant_id)
            ->where('name', Role::TENANT_ADMINISTRATOR)
            ->value('id');

        $currentRoleIds = $user->roles()->pluck('roles.id')->toArray();
        $currentlyAdmin = $tenantAdminRoleId ? in_array($tenantAdminRoleId, $currentRoleIds, true) : false;
        $willBeAdmin = $tenantAdminRoleId ? in_array($tenantAdminRoleId, $roleIds, true) : false;

        if ($tenantAdminRoleId && $currentlyAdmin && !$willBeAdmin) {
            $adminCount = User::where('tenant_id', $actor->tenant_id)
                ->whereHas('roles', function ($query) use ($tenantAdminRoleId) {
                    $query->where('roles.id', $tenantAdminRoleId)
                        ->whereNull('roles.deleted_at');
                })
                ->count();

            if ($adminCount <= 1) {
                throw new \RuntimeException('Cannot remove the last Church Administrator from this tenant.', 422);
            }
        }

        DB::transaction(function () use ($user, $roles, $roleIds, $actor, $currentRoleIds) {
            $user->syncRoles($roleIds);

            $primaryRoleId = $roles->sortBy('level')->pluck('id')->first();
            if ($primaryRoleId) {
                $user->role_id = $primaryRoleId;
                $user->save();
            }

            $newRoleIds = $roles->pluck('id')->toArray();
            $assigned = array_values(array_diff($newRoleIds, $currentRoleIds));
            $removed = array_values(array_diff($currentRoleIds, $newRoleIds));

            foreach ($assigned as $roleId) {
                $role = $roles->firstWhere('id', $roleId);
                if ($role) {
                    $this->auditService->logRoleAssignedToUser($role, $user, $actor);
                }
            }

            if (!empty($removed)) {
                $removedRoles = Role::whereIn('id', $removed)->get();
                foreach ($removedRoles as $role) {
                    $this->auditService->logRoleRemovedFromUser($role, $user, $actor);
                }
            }
        });

        return $user->fresh('roles');
    }

    private function ensureTenantActor(User $actor): void
    {
        if (!$actor->tenant_id) {
            throw new \RuntimeException('Tenant context required.', 403);
        }
    }

    private function assertTenantOwnedUser(User $actor, User $user): void
    {
        if ($user->tenant_id !== $actor->tenant_id) {
            throw new \RuntimeException('User not found or does not belong to your tenant.', 404);
        }
    }
}
