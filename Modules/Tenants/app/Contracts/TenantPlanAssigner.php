<?php

namespace Modules\Tenants\Contracts;

use Modules\Tenants\Models\Tenant;

/**
 * Assigns catalog plans to tenants. Implemented by the Subscriptions module so tenant
 * creation and the legacy upgrade endpoint go through the versioned plan catalog.
 */
interface TenantPlanAssigner
{
    /**
     * Assign the requested plan (by key) or the platform default plan to a newly created tenant.
     */
    public function assignInitialPlan(Tenant $tenant, ?string $planKey, ?int $actorId, ?string $actorRole): Tenant;

    /**
     * Legacy upgrade endpoint adapter.
     *
     * @param  array{subscription_duration_months?: int|null, allow_downgrade_non_free?: bool, reason?: string|null, source?: string}  $options
     */
    public function applyPlanByKey(Tenant $tenant, string $planKey, array $options, ?int $actorId, ?string $actorRole): Tenant;

    /**
     * Plan keys that may currently be assigned (validation allowlist).
     *
     * @return list<string>
     */
    public function assignablePlanKeys(): array;
}
