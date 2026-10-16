<?php

namespace Modules\Tenants\Contracts;

/**
 * Counts current tenant usage for one limit metric (e.g. PEOPLE_LIMIT).
 *
 * Owning modules register providers so the Subscriptions module never
 * queries another module's tables directly.
 */
interface UsageMetricProvider
{
    /**
     * Catalog feature code this provider measures (e.g. "PEOPLE_LIMIT").
     */
    public function metricCode(): string;

    /**
     * Current usage for the tenant, counted server-side from authoritative records.
     */
    public function currentUsage(int $tenantId): int;
}
