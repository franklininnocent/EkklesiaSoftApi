<?php

namespace Modules\Tenants\Services;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;
use RuntimeException;

/**
 * SSOT for support-session capability checks in middleware-adjacent layers.
 *
 * During an active owned support session, mode permissions replace parish RBAC
 * for tenant-product access. Write restrictions remain in EnforceSupportSessionMode.
 */
final class SupportSessionAuthorizationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function grantsTenantProductAccess(User $user): bool
    {
        if (! $this->tenantContext->isSupportSession()) {
            return false;
        }

        if ($this->tenantContext->actorUserId() !== (int) $user->getAuthIdentifier()) {
            return false;
        }

        $mode = $this->tenantContext->supportMode();
        if ($mode === null) {
            return false;
        }

        $modePermission = $this->modePermissionName($mode);
        if ($modePermission === null) {
            return false;
        }

        return method_exists($user, 'hasPermission') && $user->hasPermission($modePermission);
    }

    public function modePermissionName(?SupportSessionMode $mode): ?string
    {
        return match ($mode) {
            SupportSessionMode::Readonly => 'support.sessions.readonly',
            SupportSessionMode::Standard => 'support.sessions.standard',
            SupportSessionMode::Emergency => 'support.sessions.emergency',
            default => null,
        };
    }

    /**
     * Ensure breadcrumb/event writes target the elevated session only.
     *
     * @throws RuntimeException
     */
    public function assertEventSessionBinding(string $urlSessionId): void
    {
        if (! $this->tenantContext->isSupportSession()) {
            return;
        }

        $contextSessionId = $this->tenantContext->supportSessionId();
        if ($contextSessionId === null || $contextSessionId !== $urlSessionId) {
            throw new RuntimeException('Support session header does not match the requested session.');
        }
    }
}
