<?php

namespace Modules\Tenants\Support;

use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * Shared write-policy check for queue jobs and artisan commands.
 */
final class SubscriptionJobWriteGuard
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function allowsMutationsForTenantId(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            return false;
        }

        return $this->subscriptionService->isWriteAllowed($tenant);
    }
}
