<?php

namespace Modules\RolesAndPermissions\Traits;

use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;

/**
 * Trait for enforcing tenant isolation in controllers.
 * 
 * SECURITY: Provides standardized tenant isolation checks for roles and permissions
 * This ensures consistent security across all endpoints.
 */
trait EnforcesTenantIsolation
{
    /**
     * Apply tenant isolation to a query based on user's role.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param User|null $user
     * @param string $tableName Table name for logging
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyTenantIsolation($query, ?User $user = null, string $tableName = 'resource'): \Illuminate\Database\Eloquent\Builder
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            Log::warning("Tenant isolation: No authenticated user", [
                'table' => $tableName,
            ]);
            // Deny access if no user
            return $query->whereRaw('1 = 0');
        }

        // SuperAdmin sees everything
        if ($user->isSuperAdmin()) {
            Log::debug("Tenant isolation: SuperAdmin - full access", [
                'user_id' => $user->id,
                'table' => $tableName,
            ]);
            return $query; // No filter needed
        }

        // EkklesiaAdmin/Manager see global resources + their tenant's resources
        if ($user->isEkklesiaAdmin() || $user->isEkklesiaManager()) {
            if ($user->tenant_id) {
                $query->where(function ($q) use ($user) {
                    $q->whereNull('tenant_id') // Global resources
                      ->orWhere('tenant_id', $user->tenant_id); // Their tenant's resources
                });
                
                Log::debug("Tenant isolation: Ekklesia Admin/Manager - global + own tenant", [
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'table' => $tableName,
                ]);
            } else {
                // EkklesiaAdmin/Manager without tenant can only see global resources
                $query->whereNull('tenant_id');
                
                Log::debug("Tenant isolation: Ekklesia Admin/Manager without tenant - global only", [
                    'user_id' => $user->id,
                    'table' => $tableName,
                ]);
            }
            return $query;
        }

        // Tenant users - strict isolation (only their tenant's resources)
        if ($user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
            
            Log::info("Tenant isolation: Tenant user - strict isolation", [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'table' => $tableName,
            ]);
            return $query;
        }

        // Users without tenant - deny access
        Log::warning("Tenant isolation: User without tenant - access denied", [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'table' => $tableName,
        ]);
        
        return $query->whereRaw('1 = 0'); // Return empty result
    }

    /**
     * Check if user can access a resource based on tenant isolation.
     * 
     * @param mixed $resource Resource with tenant_id property
     * @param User|null $user
     * @return bool
     */
    protected function canAccessResource($resource, ?User $user = null): bool
    {
        if (!$user) {
            $user = auth()->user();
        }

        if (!$user) {
            return false;
        }

        // SuperAdmin can access everything
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Resource must have tenant_id
        if (!isset($resource->tenant_id)) {
            return false;
        }

        // Global resources (tenant_id = null) can be accessed by EkklesiaAdmin/Manager
        if (is_null($resource->tenant_id)) {
            return $user->isEkklesiaAdmin() || $user->isEkklesiaManager();
        }

        // Tenant-specific resources - must match user's tenant
        if ($user->tenant_id) {
            return $resource->tenant_id === $user->tenant_id;
        }

        // User without tenant cannot access tenant-specific resources
        return false;
    }
}

