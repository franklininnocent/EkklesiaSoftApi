<?php

namespace Modules\RolesAndPermissions\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
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
}
