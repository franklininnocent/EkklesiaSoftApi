<?php

namespace Modules\Subscriptions\Services\Catalog;

use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Subscriptions\Services\Entitlements\ResolvedEntitlements;
use Modules\Tenants\Models\Tenant;

/**
 * Compares legacy decisions (tenants.features / plan key) with plan-driven decisions for
 * every legacy-mapped feature. Zero mismatches is the gate for switching to enforce mode.
 */
class EntitlementParityChecker
{
    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCatalog $catalog,
    ) {}

    /**
     * @return array{tenants_checked: int, tenants_without_subscription: list<int>, mismatches: list<array{tenant_id: int, legacy_key: string, feature_code: string, legacy: bool, plan: bool}>}
     */
    public function check(?int $tenantId = null): array
    {
        $report = ['tenants_checked' => 0, 'tenants_without_subscription' => [], 'mismatches' => []];
        $legacyMap = $this->catalog->snapshot()['legacy'];

        $query = Tenant::query()->orderBy('id');
        if ($tenantId !== null) {
            $query->whereKey($tenantId);
        }

        $query->chunkById(200, function ($tenants) use (&$report, $legacyMap): void {
            foreach ($tenants as $tenant) {
                /** @var Tenant $tenant */
                $report['tenants_checked']++;
                $payload = $this->resolver->resolveFromSubscription((int) $tenant->id);
                if ($payload === null) {
                    $report['tenants_without_subscription'][] = (int) $tenant->id;

                    continue;
                }
                $planned = ResolvedEntitlements::fromArray($payload);

                foreach ($legacyMap as $legacyKey => $code) {
                    $legacy = $tenant->legacyFeatureDecision($legacyKey);
                    $plan = $planned->allows($code);
                    if ($legacy !== $plan) {
                        $report['mismatches'][] = [
                            'tenant_id' => (int) $tenant->id,
                            'legacy_key' => $legacyKey,
                            'feature_code' => $code,
                            'legacy' => $legacy,
                            'plan' => $plan,
                        ];
                    }
                }
            }
        });

        return $report;
    }
}
