<?php

namespace Modules\Tenants\Contracts;

/**
 * Plan-limit check for create flows in other modules (people, families, staff, storage).
 * Implemented by the Subscriptions module; unbound means "no plan limits".
 *
 * Call inside the creating DB transaction so concurrent creates are serialised.
 * Implementations throw a Responsable exception (ENTITLEMENT_LIMIT_REACHED) when blocked.
 */
interface TenantLimitGuard
{
    /**
     * @param  string|null  $flow  Business flow key (e.g. sacrament_recipient) for policy exemptions.
     */
    public function assertTenantCanAdd(int $tenantId, string $metricCode, int $adding = 1, ?string $flow = null): void;
}
