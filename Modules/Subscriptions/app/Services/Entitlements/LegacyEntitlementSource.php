<?php

namespace Modules\Subscriptions\Services\Entitlements;

use Modules\Tenants\Models\Tenant;

/**
 * Pre-plan-entitlement behaviour expressed as catalog entitlements.
 *
 * Used for tenants that have no CURRENT tenant_subscriptions row (not yet backfilled,
 * unsaved models) and as the reference for the parity check. Mirrors the rules the
 * grandfathered LEGACY_* plan versions are built from.
 */
class LegacyEntitlementSource
{
    public function __construct(private readonly EntitlementCatalog $catalog) {}

    public function resolve(Tenant $tenant): ResolvedEntitlements
    {
        $features = [];
        foreach ($this->catalog->features() as $code => $feature) {
            $features[$code] = [
                'enabled' => $this->legacyEnabled($tenant, $feature),
                'value' => null,
                'tier' => null,
                'type' => (string) $feature['type'],
                'source' => ResolvedEntitlements::SOURCE_LEGACY,
            ];
        }

        $features = DependencyEnforcer::apply($features, $this->catalog);

        return new ResolvedEntitlements(
            (int) ($tenant->getKey() ?? 0),
            ResolvedEntitlements::SOURCE_LEGACY,
            $tenant->plan ? ['key' => (string) $tenant->plan, 'code' => null, 'legacy' => true] : null,
            $features,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    public function legacyEnabled(Tenant $tenant, array $feature): bool
    {
        if ($feature['is_core']) {
            return true;
        }

        if (! empty($feature['legacy_key'])) {
            return $tenant->legacyFeatureDecision((string) $feature['legacy_key']);
        }

        if (in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)) {
            return true;
        }

        return (bool) $feature['legacy_default'];
    }
}
