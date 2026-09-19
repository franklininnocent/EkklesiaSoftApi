<?php

namespace Modules\Notifications\Support;

/**
 * Inbox scope derived from the authenticated user's home tenant only.
 * Never uses TenantContext::effectiveTenantId() (support session).
 */
final readonly class InboxContext
{
    public function __construct(
        public InboxScope $scope,
        public ?int $tenantId,
        public int $userId,
    ) {
    }

    public function cacheKeySuffix(): string
    {
        return $this->scope === InboxScope::Platform
            ? 'platform'
            : 't'.$this->tenantId;
    }
}
