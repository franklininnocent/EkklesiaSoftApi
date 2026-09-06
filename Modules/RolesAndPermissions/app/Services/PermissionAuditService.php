<?php

namespace Modules\RolesAndPermissions\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Support\AuditPiiRedactor;

/**
 * Service for logging permission and role changes for audit purposes.
 * 
 * SECURITY: Provides comprehensive audit trail for all permission-related operations
 */
class PermissionAuditService
{
    public function __construct(
        private readonly AuditPiiRedactor $piiRedactor,
    ) {}

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
        $logData = $this->piiRedactor->redact(array_merge($data, [
            'timestamp' => now()->toDateTimeString(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]));

        // Log to Laravel log file
        Log::channel('audit')->info('Permission audit', $logData);

        // Store in database (migration already created and executed)
        try {
            // Use a nested transaction so audit insert failures don't poison
            // the parent transaction in PostgreSQL-backed test runs.
            DB::transaction(function () use ($data) {
                $permissionId = $this->normalizeEntityId($data['permission_id'] ?? null);
                $roleId = $this->normalizeEntityId($data['role_id'] ?? null);
                $userId = $this->normalizeEntityId($data['user_id'] ?? null);
                $assignedByRaw = $data['assigned_by']
                    ?? $data['created_by']
                    ?? $data['updated_by']
                    ?? $data['deleted_by']
                    ?? $data['removed_by']
                    ?? null;
                $assignedById = $this->normalizeEntityId($assignedByRaw);

                DB::table('permission_audit_logs')->insert([
                    'action' => $data['action'],
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                    'user_id' => $userId,
                    'assigned_by' => $assignedById,
                    'tenant_id' => $data['tenant_id'] ?? null,
                    'metadata' => json_encode($logData),
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

    /**
     * Normalize entity IDs for relational audit columns (bigint PKs).
     */
    private function normalizeEntityId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $asInt = (int) $value;

            return $asInt > 0 ? $asInt : null;
        }

        // Legacy UUID payloads (pre-migration) are retained in metadata only.
        if (is_string($value) && Str::isUuid($value)) {
            return null;
        }

        return null;
    }
}

