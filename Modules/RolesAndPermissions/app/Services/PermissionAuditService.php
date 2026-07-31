<?php

namespace Modules\RolesAndPermissions\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Service for logging permission and role changes for audit purposes.
 * 
 * SECURITY: Provides comprehensive audit trail for all permission-related operations
 */
class PermissionAuditService
{
    /**
     * Log permission assignment to role.
     */
    public function logPermissionAssignedToRole(
        Permission $permission,
        Role $role,
        ?User $assignedBy = null
    ): void {
        $this->log([
            'action' => 'permission_assigned_to_role',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
            'role_id' => $role->id,
            'role_name' => $role->name,
            'assigned_by' => $assignedBy ? $assignedBy->id : auth()->id(),
            'assigned_by_email' => $assignedBy ? $assignedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Log permission removal from role.
     */
    public function logPermissionRemovedFromRole(
        Permission $permission,
        Role $role,
        ?User $removedBy = null
    ): void {
        $this->log([
            'action' => 'permission_removed_from_role',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
            'role_id' => $role->id,
            'role_name' => $role->name,
            'removed_by' => $removedBy ? $removedBy->id : auth()->id(),
            'removed_by_email' => $removedBy ? $removedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Log permission assignment to user.
     */
    public function logPermissionAssignedToUser(
        Permission $permission,
        User $user,
        ?User $assignedBy = null
    ): void {
        $this->log([
            'action' => 'permission_assigned_to_user',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'assigned_by' => $assignedBy ? $assignedBy->id : auth()->id(),
            'assigned_by_email' => $assignedBy ? $assignedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /**
     * Log permission removal from user.
     */
    public function logPermissionRemovedFromUser(
        Permission $permission,
        User $user,
        ?User $removedBy = null
    ): void {
        $this->log([
            'action' => 'permission_removed_from_user',
            'permission_id' => $permission->id,
            'permission_name' => $permission->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'removed_by' => $removedBy ? $removedBy->id : auth()->id(),
            'removed_by_email' => $removedBy ? $removedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /**
     * Log role assignment to user.
     */
    public function logRoleAssignedToUser(
        Role $role,
        User $user,
        ?User $assignedBy = null
    ): void {
        $this->log([
            'action' => 'role_assigned_to_user',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'assigned_by' => $assignedBy ? $assignedBy->id : auth()->id(),
            'assigned_by_email' => $assignedBy ? $assignedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /**
     * Log role removal from user.
     */
    public function logRoleRemovedFromUser(
        Role $role,
        User $user,
        ?User $removedBy = null
    ): void {
        $this->log([
            'action' => 'role_removed_from_user',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'removed_by' => $removedBy ? $removedBy->id : auth()->id(),
            'removed_by_email' => $removedBy ? $removedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $user->tenant_id,
        ]);
    }

    /**
     * Log role creation.
     */
    public function logRoleCreated(
        Role $role,
        ?User $createdBy = null
    ): void {
        $this->log([
            'action' => 'role_created',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'role_level' => $role->level,
            'is_custom' => $role->is_custom,
            'created_by' => $createdBy ? $createdBy->id : auth()->id(),
            'created_by_email' => $createdBy ? $createdBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Log role update.
     */
    public function logRoleUpdated(
        Role $role,
        array $changes,
        ?User $updatedBy = null
    ): void {
        $this->log([
            'action' => 'role_updated',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'changes' => $changes,
            'updated_by' => $updatedBy ? $updatedBy->id : auth()->id(),
            'updated_by_email' => $updatedBy ? $updatedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Log role deletion.
     */
    public function logRoleDeleted(
        Role $role,
        ?User $deletedBy = null
    ): void {
        $this->log([
            'action' => 'role_deleted',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'deleted_by' => $deletedBy ? $deletedBy->id : auth()->id(),
            'deleted_by_email' => $deletedBy ? $deletedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Log bulk permission assignment.
     */
    public function logBulkPermissionAssignment(
        Role $role,
        array $permissionIds,
        ?User $assignedBy = null
    ): void {
        $this->log([
            'action' => 'bulk_permissions_assigned_to_role',
            'role_id' => $role->id,
            'role_name' => $role->name,
            'permission_count' => count($permissionIds),
            'permission_ids' => $permissionIds,
            'assigned_by' => $assignedBy ? $assignedBy->id : auth()->id(),
            'assigned_by_email' => $assignedBy ? $assignedBy->email : (auth()->user()?->email ?? 'system'),
            'tenant_id' => $role->tenant_id,
        ]);
    }

    /**
     * Write audit log entry.
     * 
     * @param array $data
     * @return void
     */
    private function log(array $data): void
    {
        // Add timestamp and IP address
        $logData = array_merge($data, [
            'timestamp' => now()->toDateTimeString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Log to Laravel log file
        Log::channel('audit')->info('Permission audit', $logData);

        // Store in database (migration already created and executed)
        try {
            // Use a nested transaction so audit insert failures don't poison
            // the parent transaction in PostgreSQL-backed test runs.
            DB::transaction(function () use ($data) {
                $permissionId = isset($data['permission_id']) && Str::isUuid((string) $data['permission_id'])
                    ? (string) $data['permission_id']
                    : null;
                $roleId = isset($data['role_id']) && Str::isUuid((string) $data['role_id'])
                    ? (string) $data['role_id']
                    : null;
                $userId = isset($data['user_id']) && Str::isUuid((string) $data['user_id'])
                    ? (string) $data['user_id']
                    : null;
                $assignedBy = $data['assigned_by'] ?? $data['created_by'] ?? $data['updated_by'] ?? $data['deleted_by'] ?? null;
                $assignedById = !is_null($assignedBy) && Str::isUuid((string) $assignedBy)
                    ? (string) $assignedBy
                    : null;

                DB::table('permission_audit_logs')->insert([
                    'action' => $data['action'],
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'user_id' => $userId,
                    'assigned_by' => $assignedById,
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'metadata' => json_encode($data),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Exception $e) {
            // If table doesn't exist or error occurs, just log to file
            Log::warning('Could not write to audit_logs table: ' . $e->getMessage());
        }
    }
}

