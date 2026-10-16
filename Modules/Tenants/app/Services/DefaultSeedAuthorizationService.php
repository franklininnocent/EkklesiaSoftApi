<?php

namespace Modules\Tenants\Services;

use Modules\Authentication\Models\User;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DefaultSeedAuthorizationService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function assertCanAccessCatalog(User $user, TenantContext $context): int
    {
        return $this->requireTenantScopedUser($user, $context);
    }

    public function assertCanExecute(User $user, TenantContext $context): int
    {
        $tenantId = $this->requireTenantScopedUser($user, $context);

        if ($context->isSupportSession() && $context->supportMode() === SupportSessionMode::Readonly) {
            throw new HttpException(403, 'Support session is read-only. Default lists cannot be added in this mode.');
        }

        return $tenantId;
    }

    public function canRunDefinition(User $user, Tenant $tenant, TenantDefaultSeedDefinition $definition): bool
    {
        if (! $this->featureEnabled($tenant, $definition->featureKey())) {
            return false;
        }

        return $this->userHasDomainPermission($user, $definition->requiredPermission());
    }

    public function featureEnabled(Tenant $tenant, string $featureKey): bool
    {
        return $this->subscriptionService->evaluateModuleAccess($tenant, $featureKey)['allowed'];
    }

    private function requireTenantScopedUser(User $user, TenantContext $context): int
    {
        $tenantId = $context->requireEffectiveTenantId();

        if (
            $user->tenant_id === null
            && method_exists($user, 'hasEkklesiaRole')
            && $user->hasEkklesiaRole()
            && ! app(SupportSessionAuthorizationService::class)->grantsTenantProductAccess($user)
        ) {
            throw new HttpException(403, 'Default lists can only be managed within a parish account or an active Support Center session.');
        }

        if ($user->tenant_id !== null && (int) $user->tenant_id !== $tenantId) {
            throw new HttpException(403, 'Tenant context required.');
        }

        return $tenantId;
    }

    private function userHasDomainPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        if ($user->isTenantAdmin()) {
            return true;
        }

        if ($user->is_primary_admin ?? false) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
