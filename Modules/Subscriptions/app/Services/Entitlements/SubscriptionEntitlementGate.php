<?php

namespace Modules\Subscriptions\Services\Entitlements;

use Illuminate\Support\Facades\Log;
use Modules\Subscriptions\Services\SubscriptionPresenter;
use Modules\Subscriptions\Support\EntitlementEngineMode;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use Modules\Tenants\Models\Tenant;
use Throwable;

/**
 * Tenants-module gate backed by plan entitlements, honouring the rollout mode.
 *
 * legacy: legacy decision; shadow: legacy decision + mismatch log; enforce: plan decision.
 * Resolution failures fall back to the legacy decision (pre-existing behaviour) and are reported.
 */
class SubscriptionEntitlementGate implements TenantEntitlementGate
{
    /** @var array<string, true> */
    private array $loggedMismatches = [];

    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly EntitlementCatalog $catalog,
        private readonly LegacyEntitlementSource $legacySource,
    ) {}

    public function decideLegacyFeature(Tenant $tenant, string $legacyKey, bool $legacyDecision): bool
    {
        $mode = EntitlementEngineMode::current();
        if ($mode === EntitlementEngineMode::LEGACY || ! $tenant->getKey()) {
            return $legacyDecision;
        }

        try {
            $code = $this->catalog->codeForLegacyKey($legacyKey);
            if ($code === null) {
                return $legacyDecision;
            }
            $planDecision = $this->resolver->resolve($tenant)->allows($code);
        } catch (Throwable $e) {
            report($e);

            return $legacyDecision;
        }

        return $this->choose($mode, $tenant, $code, $legacyDecision, $planDecision);
    }

    public function allows(Tenant $tenant, string $featureCode): bool
    {
        $code = strtoupper($featureCode);
        $mode = EntitlementEngineMode::current();
        $legacyDecision = $this->legacySource->resolve($tenant)->allows($code);

        if ($mode === EntitlementEngineMode::LEGACY || ! $tenant->getKey()) {
            return $legacyDecision;
        }

        try {
            $planDecision = $this->resolver->resolve($tenant)->allows($code);
        } catch (Throwable $e) {
            report($e);

            return $legacyDecision;
        }

        return $this->choose($mode, $tenant, $code, $legacyDecision, $planDecision);
    }

    public function limit(Tenant $tenant, string $featureCode): ?int
    {
        if (! EntitlementEngineMode::isEnforcing() || ! $tenant->getKey()) {
            return null;
        }

        return $this->resolver->resolve($tenant)->limit($featureCode);
    }

    public function forget(Tenant|int $tenant): void
    {
        $this->resolver->forget($tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant);
    }

    public function accessSummary(Tenant $tenant, bool $withUsage = false): array
    {
        return app(SubscriptionPresenter::class)->accessSummary($tenant, $withUsage);
    }

    private function choose(string $mode, Tenant $tenant, string $code, bool $legacyDecision, bool $planDecision): bool
    {
        if ($mode === EntitlementEngineMode::ENFORCE) {
            return $planDecision;
        }

        if ($legacyDecision !== $planDecision) {
            $marker = $tenant->getKey().':'.$code;
            if (! isset($this->loggedMismatches[$marker])) {
                $this->loggedMismatches[$marker] = true;
                Log::warning('subscriptions.entitlement_shadow_mismatch', [
                    'tenant_id' => (int) $tenant->getKey(),
                    'feature_code' => $code,
                    'legacy_decision' => $legacyDecision,
                    'plan_decision' => $planDecision,
                ]);
            }
        }

        return $legacyDecision;
    }
}
