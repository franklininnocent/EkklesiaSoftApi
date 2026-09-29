<?php

namespace Modules\Subscriptions\Services\Catalog;

use Modules\Subscriptions\Models\PlanVersion;

/**
 * Deterministic hash of a version's commercial terms + entitlements, used to detect
 * tampering with published (immutable) versions.
 */
class PlanVersionContentHasher
{
    public function hash(PlanVersion $version): string
    {
        $version->loadMissing('entitlements');

        $entitlements = $version->entitlements
            ->map(static fn ($e) => [
                (int) $e->feature_id,
                (bool) $e->is_enabled,
                $e->numeric_value === null ? null : (int) $e->numeric_value,
                $e->tier_value,
            ])
            ->sortBy(static fn (array $row) => $row[0])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'plan_id' => (int) $version->plan_id,
            'version_number' => (int) $version->version_number,
            'currency_code' => $version->currency_code,
            'monthly_price' => $version->monthly_price === null ? null : (string) $version->monthly_price,
            'annual_price' => $version->annual_price === null ? null : (string) $version->annual_price,
            'setup_fee' => $version->setup_fee === null ? null : (string) $version->setup_fee,
            'tax_inclusive' => (bool) $version->tax_inclusive,
            'tax_rate_percent' => $version->tax_rate_percent === null ? null : (string) $version->tax_rate_percent,
            'trial_days' => $version->trial_days,
            'billing_intervals' => array_values((array) $version->billing_intervals),
            'entitlements' => $entitlements,
        ]));
    }
}
