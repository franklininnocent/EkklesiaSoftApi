<?php

namespace Modules\Tenants\Policies\Concerns;

use Modules\Authentication\Models\User;
use Modules\Tenants\Services\SupportSessionAuthorizationService;
use Modules\Tenants\Support\EffectiveTenant;

trait AuthorizesTenantPermission
{
    protected function allows(User $user, string $permission): bool
    {
        if (app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)) {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ($user->isTenantAdmin()) {
            return true;
        }

        return $user->hasPermission($permission);
    }

    protected function matchesTenant(User $user, int|string|null $resourceTenantId): bool
    {
        return EffectiveTenant::matches($user, $resourceTenantId);
    }
}
