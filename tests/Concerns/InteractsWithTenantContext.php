<?php

namespace Tests\Concerns;

use Modules\Authentication\Models\User;
use Modules\Tenants\Support\ActiveSupportSession;
use Modules\Tenants\Support\SupportSessionMode;
use Modules\Tenants\Support\TenantContext;

trait InteractsWithTenantContext
{
    protected function bindTenantContext(
        ?User $user = null,
        ?int $effectiveTenantId = null,
        ?ActiveSupportSession $supportSession = null,
    ): TenantContext {
        $context = TenantContext::fromUserAndSession($user, $supportSession);

        if ($effectiveTenantId !== null && $supportSession === null) {
            $actorUserId = $user?->getAuthIdentifier();
            $homeTenantId = $user?->tenant_id !== null ? (int) $user->tenant_id : null;
            $context = new TenantContext(
                $actorUserId !== null ? (int) $actorUserId : null,
                $homeTenantId,
                $effectiveTenantId,
                null,
                null,
            );
        }

        app()->instance(TenantContext::class, $context);

        return $context;
    }

    protected function bindSupportTenantContext(User $user, int $targetTenantId): TenantContext
    {
        $session = new ActiveSupportSession(
            id: 'test-support-session',
            tenantId: $targetTenantId,
            mode: SupportSessionMode::Readonly,
            supportUserId: (int) $user->getAuthIdentifier(),
            expiresAt: new \DateTimeImmutable('+1 hour'),
            reasonCode: 'test',
        );

        return $this->bindTenantContext($user, null, $session);
    }
}
