<?php

namespace Modules\Tenants\Support;

use DateTimeInterface;

/**
 * Immutable snapshot of an active support session for TenantContext binding.
 * Populated by SupportAccess in Phase 1; unused while NullSupportSessionResolver is bound.
 */
final class ActiveSupportSession
{
    public function __construct(
        private readonly string $id,
        private readonly int $tenantId,
        private readonly SupportSessionMode $mode,
        private readonly int $supportUserId,
        private readonly DateTimeInterface $expiresAt,
        private readonly string $reasonCode,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function mode(): SupportSessionMode
    {
        return $this->mode;
    }

    public function supportUserId(): int
    {
        return $this->supportUserId;
    }

    public function expiresAt(): DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
