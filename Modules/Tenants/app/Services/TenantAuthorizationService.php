<?php

namespace Modules\Tenants\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Authentication\Models\User;
use Modules\Tenants\Support\EffectiveTenant;
use Modules\Tenants\Support\TenantContext;
use Modules\Tenants\Support\TenantQueryGuard;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resource-level tenant authorization for product models carrying tenant_id.
 */
final class TenantAuthorizationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @throws HttpException
     */
    public function requireEffectiveTenantId(): int
    {
        return $this->tenantContext->requireEffectiveTenantId();
    }

    public function effectiveTenantId(): ?int
    {
        return $this->tenantContext->effectiveTenantId();
    }

    public function resourceBelongsToEffectiveTenant(Model $resource, string $tenantColumn = 'tenant_id'): bool
    {
        if (! isset($resource->{$tenantColumn})) {
            return false;
        }

        return TenantQueryGuard::matchesResourceTenant($resource->{$tenantColumn});
    }

    /**
     * Authorize access to a tenant-owned resource in the effective tenant.
     *
     * @throws NotFoundHttpException when the resource is outside the effective tenant
     */
    public function authorizeResource(Model $resource, string $tenantColumn = 'tenant_id'): void
    {
        $this->requireEffectiveTenantId();

        if (! $this->resourceBelongsToEffectiveTenant($resource, $tenantColumn)) {
            throw new NotFoundHttpException('Resource not found.');
        }
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function authorizeActorCanAccessResource(?User $user, Model $resource, string $tenantColumn = 'tenant_id'): void
    {
        if ($user === null) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        if (! isset($resource->{$tenantColumn})) {
            throw new NotFoundHttpException('Resource not found.');
        }

        if (! EffectiveTenant::matches($user, $resource->{$tenantColumn})) {
            throw new NotFoundHttpException('Resource not found.');
        }
    }
}
