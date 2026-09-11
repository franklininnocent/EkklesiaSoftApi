<?php

namespace Modules\ApplicationAccess\Support;

use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\TenantContext;

final class ApplicationAccessIdentityResolver
{
    public function resolveIdentityType(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return ApplicationAccessEnums::IDENTITY_SUPER_ADMIN;
        }

        if ($this->isSupportOperator($user)) {
            return ApplicationAccessEnums::IDENTITY_SUPPORT_OPERATOR;
        }

        if ($user->hasEkklesiaRole()) {
            return ApplicationAccessEnums::IDENTITY_EKKLESIA_USER;
        }

        if ($user->tenant_id) {
            return ApplicationAccessEnums::IDENTITY_TENANT_USER;
        }

        return ApplicationAccessEnums::IDENTITY_NO_TENANT;
    }

    public function resolveAccessContext(User $user, ?string $authContext = null): string
    {
        if ($authContext === 'login' || $authContext === 'register' || $authContext === 'refresh') {
            return ApplicationAccessEnums::CONTEXT_AUTHENTICATION;
        }

        if ($this->isSupportOperator($user)) {
            return ApplicationAccessEnums::CONTEXT_SUPPORT;
        }

        if ($user->hasEkklesiaRole() && ! $user->tenant_id) {
            return ApplicationAccessEnums::CONTEXT_EKKLESIA;
        }

        if ($user->tenant_id) {
            return ApplicationAccessEnums::CONTEXT_TENANT;
        }

        return ApplicationAccessEnums::CONTEXT_API;
    }

    private function isSupportOperator(User $user): bool
    {
        if (! app()->bound(TenantContext::class)) {
            return false;
        }

        $context = app(TenantContext::class);

        return $context->isSupportSession()
            && $context->actorUserId() === (int) $user->getAuthIdentifier();
    }
}
