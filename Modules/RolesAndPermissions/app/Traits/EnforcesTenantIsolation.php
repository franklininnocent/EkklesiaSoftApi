<?php

namespace Modules\RolesAndPermissions\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\Tenants\Support\TenantQueryGuard;

/**
 * Trait for enforcing tenant isolation in controllers.
 *
 * SECURITY: Uses request-scoped TenantContext via EffectiveTenant / TenantQueryGuard.
 */
trait EnforcesTenantIsolation
{
    /**
     * Apply tenant isolation to a query based on effective tenant context.
     */
    protected function applyTenantIsolation($query, ?User $user = null, string $tableName = 'resource'): Builder
    {
        $user ??= auth()->user();

        if (! $user) {
            Log::warning('Tenant isolation: No authenticated user', [
                'table' => $tableName,
            ]);

            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            Log::debug('Tenant isolation: SuperAdmin - full access', [
                'user_id' => $user->id,
                'table' => $tableName,
            ]);

            return $query;
        }

        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            $effectiveTenantId = EffectiveTenant::id($user);

            if ($effectiveTenantId !== null) {
                $query->where(function ($q) use ($effectiveTenantId) {
                    $q->whereNull('tenant_id')
                        ->orWhere('tenant_id', $effectiveTenantId);
                });

                Log::debug('Tenant isolation: Ekklesia Admin/Manager - global + effective tenant', [
                    'user_id' => $user->id,
                    'tenant_id' => $effectiveTenantId,
                    'table' => $tableName,
                ]);
            } else {
                $query->whereNull('tenant_id');

                Log::debug('Tenant isolation: Ekklesia Admin/Manager without effective tenant - global only', [
                    'user_id' => $user->id,
                    'table' => $tableName,
                ]);
            }

            return $query;
        }

        return TenantQueryGuard::apply($query, 'tenant_id');
    }

    /**
     * Check if user can access a resource based on tenant isolation.
     */
    protected function canAccessResource($resource, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! isset($resource->tenant_id)) {
            return false;
        }

        if (is_null($resource->tenant_id)) {
            return $user->isEkklesiaAdmin() || $user->isEkklesiaManager();
        }

        return EffectiveTenant::matches($user, $resource->tenant_id);
    }

    /**
     * Apply role list scoping for GET /roles and GET /tenant/roles.
     *
     * @return JsonResponse|null 403 response when access must be denied
     */
    protected function applyRoleListScope(Builder $query, ?User $user = null): ?JsonResponse
    {
        $user ??= auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            $effectiveTenantId = EffectiveTenant::id($user);

            if ($effectiveTenantId !== null) {
                $query->where(function ($q) use ($effectiveTenantId) {
                    $q->whereNull('tenant_id')
                        ->orWhere('tenant_id', $effectiveTenantId);
                });
            } else {
                $query->whereNull('tenant_id');
            }

            return null;
        }

        if ($user->isEkklesiaUser()) {
            $effectiveTenantId = EffectiveTenant::id($user);

            if ($effectiveTenantId !== null) {
                $query->where('tenant_id', $effectiveTenantId);
            } else {
                $query->whereNull('tenant_id');
            }

            return null;
        }

        $effectiveTenantId = EffectiveTenant::id($user);
        if ($effectiveTenantId !== null) {
            $query->where('tenant_id', $effectiveTenantId);

            return null;
        }

        Log::warning('Roles query: User without tenant attempted access', [
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Access denied: Invalid user tenant association',
        ], 403);
    }

    /**
     * Apply permission list scoping for GET /permissions.
     *
     * @return JsonResponse|null 403 response when access must be denied
     */
    protected function applyPermissionListScope(Builder $query, ?User $user = null): ?JsonResponse
    {
        $user ??= auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        $effectiveTenantId = EffectiveTenant::id($user);

        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager() || $user->isEkklesiaUser()) {
            $this->applyTenantPermissionCatalogScope($query, $effectiveTenantId);

            return null;
        }

        if ($effectiveTenantId !== null) {
            $this->applyTenantPermissionCatalogScope($query, $effectiveTenantId);

            return null;
        }

        Log::warning('Permissions query: User without tenant attempted access', [
            'user_id' => $user->id,
            'user_email' => $user->email,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Access denied: Invalid user tenant association',
        ], 403);
    }

    protected function applyTenantPermissionCatalogScope(Builder $query, ?int $effectiveTenantId): void
    {
        $query->where(function ($q) use ($effectiveTenantId) {
            $q->where(function ($subQ) {
                $subQ->whereNull('tenant_id')
                    ->where('is_custom', false)
                    ->whereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH])
                    ->where('module', '!=', 'Tenants')
                    ->where('module', '!=', 'Pope');
            });

            if ($effectiveTenantId !== null) {
                $q->orWhere(function ($subQ) use ($effectiveTenantId) {
                    $subQ->where('tenant_id', $effectiveTenantId)
                        ->where('is_custom', true);
                });
            }
        });
    }

    protected function canViewPermissionRecord(Permission $permission, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($permission->module === 'Tenants' || $permission->module === 'Pope') {
            return false;
        }

        if ($permission->scope === Permission::SCOPE_PLATFORM) {
            return false;
        }

        $effectiveTenantId = EffectiveTenant::id($user);

        if (is_null($permission->tenant_id) && ! $permission->is_custom) {
            return in_array($permission->scope, [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH], true);
        }

        if ($permission->is_custom && $effectiveTenantId !== null) {
            return (int) $permission->tenant_id === $effectiveTenantId;
        }

        return false;
    }
}
