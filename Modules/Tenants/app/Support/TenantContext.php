<?php

namespace Modules\Tenants\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Request-scoped source of truth for actor identity vs effective tenant.
 *
 * Never mutate users.tenant_id. Support sessions (Phase 1) may override
 * effectiveTenantId while actorUserId remains the Support Admin.
 */
final class TenantContext
{
    public function __construct(
        private readonly ?int $actorUserId,
        private readonly ?int $homeTenantId,
        private readonly ?int $effectiveTenantId,
        private readonly ?string $supportSessionId,
        private readonly ?SupportSessionMode $supportMode,
    ) {
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null);
    }

    public static function fromUserAndSession(?Authenticatable $user, ?ActiveSupportSession $session): self
    {
        if ($user === null) {
            return self::empty();
        }

        $actorUserId = (int) $user->getAuthIdentifier();
        $homeTenantId = isset($user->tenant_id) && $user->tenant_id !== null
            ? (int) $user->tenant_id
            : null;

        if ($session !== null) {
            return new self(
                $actorUserId,
                $homeTenantId,
                $session->tenantId(),
                $session->id(),
                $session->mode(),
            );
        }

        return new self(
            $actorUserId,
            $homeTenantId,
            $homeTenantId,
            null,
            null,
        );
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserId;
    }

    public function homeTenantId(): ?int
    {
        return $this->homeTenantId;
    }

    public function effectiveTenantId(): ?int
    {
        return $this->effectiveTenantId;
    }

    public function supportSessionId(): ?string
    {
        return $this->supportSessionId;
    }

    public function supportMode(): ?SupportSessionMode
    {
        return $this->supportMode;
    }

    public function isSupportSession(): bool
    {
        return $this->supportSessionId !== null;
    }

    /**
     * Effective tenant for product scoping. Never returns 0.
     *
     * @throws HttpException
     */
    public function requireEffectiveTenantId(): int
    {
        if ($this->effectiveTenantId === null || $this->effectiveTenantId <= 0) {
            throw new HttpException(403, 'Tenant context required.');
        }

        return $this->effectiveTenantId;
    }
}
