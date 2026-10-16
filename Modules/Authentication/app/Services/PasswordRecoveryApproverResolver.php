<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Collection;
use Modules\Authentication\Models\PasswordRecoveryRequest;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;

class PasswordRecoveryApproverResolver
{
    /**
     * @return Collection<int, User>
     */
    public function resolveSuperAdmins(): Collection
    {
        return User::query()
            ->where('active', 1)
            ->whereNull('tenant_id')
            ->where(function ($query) {
                $query->whereHas('role', function ($roleQuery) {
                    $roleQuery->where('name', Role::SUPER_ADMIN)
                        ->where('active', 1)
                        ->whereNull('deleted_at');
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->where('name', Role::SUPER_ADMIN)
                        ->where('active', 1)
                        ->whereNull('deleted_at');
                });
            })
            ->get()
            ->unique('id')
            ->values();
    }

    public function resolveTenantPrimaryAdmin(int $tenantId): ?User
    {
        $admins = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_primary_admin', true)
            ->where('active', 1)
            ->get();

        if ($admins->count() !== 1) {
            return null;
        }

        return $admins->first();
    }

    public function classifyRequester(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN;
        }

        if ($user->is_primary_admin) {
            return PasswordRecoveryRequest::CLASSIFICATION_TENANT_ADMIN;
        }

        if ($user->hasEkklesiaRole()) {
            return PasswordRecoveryRequest::CLASSIFICATION_EKKLESIA_USER;
        }

        return PasswordRecoveryRequest::CLASSIFICATION_TENANT_USER;
    }

    public function resolveDomain(User $user): string
    {
        return $user->tenant_id === null
            ? PasswordRecoveryRequest::DOMAIN_PLATFORM
            : PasswordRecoveryRequest::DOMAIN_TENANT;
    }

    public function requiresApproval(string $classification): bool
    {
        return $classification !== PasswordRecoveryRequest::CLASSIFICATION_SUPER_ADMIN;
    }
}
