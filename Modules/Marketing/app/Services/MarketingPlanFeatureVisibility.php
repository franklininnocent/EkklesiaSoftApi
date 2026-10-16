<?php

namespace Modules\Marketing\Services;

use InvalidArgumentException;
use Modules\Subscriptions\Models\Feature;

/**
 * Plan-specific marketing display overlay. Never reads or writes plan_entitlements.
 */
class MarketingPlanFeatureVisibility
{
    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @return array<string, array<string, true>>
     */
    public function eligibleCodesByPlan(array $eligiblePlans): array
    {
        $out = [];
        foreach ($eligiblePlans as $plan) {
            $code = (string) ($plan['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $out[$code] = [];
            foreach (['features', 'limits', 'coverage'] as $section) {
                foreach (is_array($plan[$section] ?? null) ? $plan[$section] : [] as $row) {
                    if (! is_array($row) || ! isset($row['code']) || (string) $row['code'] === '') {
                        continue;
                    }
                    $out[$code][(string) $row['code']] = true;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return array<string, list<string>>
     */
    public function normalizeHiddenMap(array $visibility): array
    {
        $hidden = [];
        $raw = is_array($visibility['hidden'] ?? null) ? $visibility['hidden'] : [];
        if ($raw === [] && ! array_key_exists('hidden', $visibility) && ! array_key_exists('hidden_plans', $visibility)) {
            $raw = $visibility;
        }
        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw as $planCode => $codes) {
            if (! is_string($planCode) || $planCode === '' || ! is_array($codes)) {
                continue;
            }
            $unique = [];
            foreach ($codes as $featureCode) {
                if (! is_string($featureCode) && ! is_int($featureCode)) {
                    continue;
                }
                $normalized = strtoupper(trim((string) $featureCode));
                if ($normalized === '' || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $normalized)) {
                    throw new InvalidArgumentException("Invalid feature code: {$featureCode}.");
                }
                $unique[$normalized] = true;
            }
            $hidden[strtoupper(trim($planCode))] = array_keys($unique);
        }

        ksort($hidden);

        return $hidden;
    }

    /**
     * @param  array<string, mixed>  $visibility
     * @return list<string>
     */
    public function normalizeHiddenPlans(array $visibility): array
    {
        $raw = $visibility['hidden_plans'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $unique = [];
        foreach ($raw as $planCode) {
            if (! is_string($planCode) && ! is_int($planCode)) {
                continue;
            }
            $normalized = strtoupper(trim((string) $planCode));
            if ($normalized === '' || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $normalized)) {
                throw new InvalidArgumentException("Invalid plan code: {$planCode}.");
            }
            $unique[$normalized] = true;
        }
        $codes = array_keys($unique);
        sort($codes);

        return $codes;
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  list<string>  $hiddenPlans
     */
    public function assertHiddenPlansAllowed(array $eligiblePlans, array $hiddenPlans): void
    {
        $eligible = $this->eligibleCodesByPlan($eligiblePlans);
        foreach ($hiddenPlans as $planCode) {
            if (! isset($eligible[$planCode])) {
                throw new InvalidArgumentException("Unknown plan code in website listing: {$planCode}.");
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  array<string, list<string>>  $hidden
     */
    public function assertHiddenMapAllowed(array $eligiblePlans, array $hidden): void
    {
        $eligible = $this->eligibleCodesByPlan($eligiblePlans);
        foreach ($hidden as $planCode => $codes) {
            if (! isset($eligible[$planCode])) {
                throw new InvalidArgumentException("Unknown plan code in visibility map: {$planCode}.");
            }
            foreach ($codes as $featureCode) {
                if (! isset($eligible[$planCode][$featureCode])) {
                    throw new InvalidArgumentException("{$planCode}: {$featureCode} is not eligible for marketing display on this revision.");
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  array<string, list<string>>  $hidden
     * @param  list<string>  $hiddenPlans
     * @return list<string>
     */
    public function assertVisibleFeaturesArePubliclyAllowed(array $eligiblePlans, array $hidden, array $hiddenPlans = []): array
    {
        $blocked = [];
        $codes = [];
        $hiddenPlans = array_fill_keys($hiddenPlans, true);
        foreach ($this->eligibleCodesByPlan($eligiblePlans) as $planCode => $featureCodes) {
            if (isset($hiddenPlans[$planCode])) {
                continue;
            }
            $hiddenSet = array_fill_keys($hidden[$planCode] ?? [], true);
            foreach (array_keys($featureCodes) as $featureCode) {
                if (! isset($hiddenSet[$featureCode])) {
                    $codes[$featureCode] = true;
                }
            }
        }

        if ($codes === []) {
            return [];
        }

        $rows = Feature::query()->whereIn('code', array_keys($codes))->get()->keyBy('code');
        foreach (array_keys($codes) as $code) {
            $feature = $rows->get($code);
            if ($feature === null || ! $feature->is_active || ! $feature->is_public) {
                $blocked[] = $code;
            }
        }

        if ($blocked !== []) {
            throw new InvalidArgumentException(
                'These features cannot appear on the marketing website: '.implode(', ', $blocked).'. Hide them or generate a new preview.'
            );
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  array<string, list<string>>  $hidden
     * @param  list<string>  $hiddenPlans
     * @return list<array<string, mixed>>
     */
    public function applyToPublicPayload(array $eligiblePlans, array $hidden, array $hiddenPlans = []): array
    {
        $plans = json_decode(json_encode($eligiblePlans), true);
        if (! is_array($plans)) {
            return [];
        }

        $omit = array_fill_keys($hiddenPlans, true);
        $plans = array_values(array_filter($plans, static function ($plan) use ($omit): bool {
            return is_array($plan) && ! isset($omit[(string) ($plan['code'] ?? '')]);
        }));

        foreach ($plans as &$plan) {
            $planCode = (string) ($plan['code'] ?? '');
            $hiddenSet = array_fill_keys($hidden[$planCode] ?? [], true);
            foreach (['features', 'limits', 'coverage'] as $section) {
                $rows = is_array($plan[$section] ?? null) ? $plan[$section] : [];
                $plan[$section] = array_values(array_filter($rows, static function ($row) use ($hiddenSet): bool {
                    if (! is_array($row) || ! isset($row['code'])) {
                        return false;
                    }

                    return ! isset($hiddenSet[(string) $row['code']]);
                }));
            }
        }
        unset($plan);

        return array_values($plans);
    }

    /**
     * @param  array<string, list<string>>  $previous
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @return array<string, list<string>>
     */
    public function carryForwardHidden(array $previous, array $eligiblePlans): array
    {
        $eligible = $this->eligibleCodesByPlan($eligiblePlans);
        $next = [];
        foreach ($previous as $planCode => $codes) {
            if (! isset($eligible[$planCode])) {
                continue;
            }
            $kept = [];
            foreach ($codes as $featureCode) {
                if (isset($eligible[$planCode][$featureCode])) {
                    $kept[] = $featureCode;
                }
            }
            if ($kept !== []) {
                $next[$planCode] = $kept;
            }
        }

        return $next;
    }

    /**
     * @param  list<string>  $previous
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @return list<string>
     */
    public function carryForwardHiddenPlans(array $previous, array $eligiblePlans): array
    {
        $eligible = $this->eligibleCodesByPlan($eligiblePlans);
        $kept = [];
        foreach ($previous as $planCode) {
            if (isset($eligible[$planCode])) {
                $kept[] = $planCode;
            }
        }
        sort($kept);

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  array<string, list<string>>  $hidden
     * @param  list<string>  $hiddenPlans
     * @return list<string>
     */
    public function hiddenFromEveryPlan(array $eligiblePlans, array $hidden, array $hiddenPlans = []): array
    {
        $plansWithFeature = [];
        $hiddenEverywhere = [];
        $omit = array_fill_keys($hiddenPlans, true);
        foreach ($this->eligibleCodesByPlan($eligiblePlans) as $planCode => $codes) {
            if (isset($omit[$planCode])) {
                continue;
            }
            foreach (array_keys($codes) as $featureCode) {
                $plansWithFeature[$featureCode][$planCode] = true;
            }
        }

        foreach ($plansWithFeature as $featureCode => $plans) {
            $allHidden = true;
            foreach (array_keys($plans) as $planCode) {
                if (! in_array($featureCode, $hidden[$planCode] ?? [], true)) {
                    $allHidden = false;
                    break;
                }
            }
            if ($allHidden) {
                $hiddenEverywhere[] = $featureCode;
            }
        }

        sort($hiddenEverywhere);

        return $hiddenEverywhere;
    }

    /**
     * @param  list<array<string, mixed>>  $eligiblePlans
     * @param  array<string, list<string>>  $hidden
     * @param  array<string, list<string>>  $publishedHidden
     * @param  list<string>  $hiddenPlans
     * @param  list<string>  $publishedHiddenPlans
     * @return array<string, mixed>
     */
    public function editorView(
        array $eligiblePlans,
        array $hidden,
        array $publishedHidden = [],
        array $hiddenPlans = [],
        array $publishedHiddenPlans = [],
    ): array {
        $plans = [];
        $nameByCode = [];
        $omit = array_fill_keys($hiddenPlans, true);
        foreach ($eligiblePlans as $plan) {
            $planCode = (string) ($plan['code'] ?? '');
            if ($planCode === '') {
                continue;
            }
            $hiddenSet = array_fill_keys($hidden[$planCode] ?? [], true);
            $features = $this->uniqueFeatureRows($plan);
            $rows = [];
            $visibleCount = 0;
            $hiddenCount = 0;
            foreach ($features as $row) {
                $code = (string) $row['code'];
                $nameByCode[$code] = (string) ($row['name'] ?? $code);
                $visible = ! isset($hiddenSet[$code]);
                if ($visible) {
                    $visibleCount++;
                } else {
                    $hiddenCount++;
                }
                $rows[] = [
                    'code' => $code,
                    'name' => (string) ($row['name'] ?? $code),
                    'description' => isset($row['description']) ? (string) $row['description'] : null,
                    'category' => isset($row['category']) ? (string) $row['category'] : 'Features',
                    'kind' => (($row['feature_type'] ?? '') === 'LIMIT' || isset($row['unit'])) ? 'limit' : 'feature',
                    'included' => (bool) ($row['included'] ?? true),
                    'visible' => $visible,
                ];
            }
            $plans[] = [
                'code' => $planCode,
                'name' => (string) ($plan['name'] ?? $planCode),
                'listed' => ! isset($omit[$planCode]),
                'short_description' => isset($plan['short_description']) ? (string) $plan['short_description'] : null,
                'badge_label' => isset($plan['badge_label']) ? (string) $plan['badge_label'] : null,
                'pricing_type' => isset($plan['pricing_type']) ? (string) $plan['pricing_type'] : null,
                'currency_code' => isset($plan['currency_code']) ? (string) $plan['currency_code'] : null,
                'monthly_price' => $plan['monthly_price'] ?? null,
                'annual_price' => $plan['annual_price'] ?? null,
                'billing_intervals' => is_array($plan['billing_intervals'] ?? null) ? $plan['billing_intervals'] : [],
                'limits' => is_array($plan['limits'] ?? null) ? $plan['limits'] : [],
                'visible_count' => $visibleCount,
                'hidden_count' => $hiddenCount,
                'features' => $rows,
            ];
        }

        $changes = $this->diffHiddenMaps($publishedHidden, $hidden, $nameByCode);
        $planChanges = $this->diffHiddenPlans($publishedHiddenPlans, $hiddenPlans);

        return [
            'plans' => $plans,
            'hidden' => $hidden,
            'hidden_plans' => $hiddenPlans,
            'hidden_from_all' => $this->hiddenFromEveryPlan($eligiblePlans, $hidden, $hiddenPlans),
            'changes_from_published' => $changes + ['plans' => $planChanges],
            'public_payload' => $this->applyToPublicPayload($eligiblePlans, $hidden, $hiddenPlans),
        ];
    }

    /**
     * @param  list<string>  $from
     * @param  list<string>  $to
     * @return array{hidden: list<string>, restored: list<string>}
     */
    public function diffHiddenPlans(array $from, array $to): array
    {
        return [
            'hidden' => array_values(array_diff($to, $from)),
            'restored' => array_values(array_diff($from, $to)),
        ];
    }

    /**
     * @param  array<string, list<string>>  $from
     * @param  array<string, list<string>>  $to
     * @param  array<string, string>  $nameByCode
     * @return array{hidden: list<array<string, string>>, restored: list<array<string, string>>}
     */
    public function diffHiddenMaps(array $from, array $to, array $nameByCode = []): array
    {
        $hidden = [];
        $restored = [];
        $planCodes = array_unique(array_merge(array_keys($from), array_keys($to)));
        sort($planCodes);

        foreach ($planCodes as $planCode) {
            $before = array_fill_keys($from[$planCode] ?? [], true);
            $after = array_fill_keys($to[$planCode] ?? [], true);
            foreach (array_keys($after) as $code) {
                if (! isset($before[$code])) {
                    $hidden[] = [
                        'plan_code' => $planCode,
                        'feature_code' => $code,
                        'name' => $nameByCode[$code] ?? $code,
                    ];
                }
            }
            foreach (array_keys($before) as $code) {
                if (! isset($after[$code])) {
                    $restored[] = [
                        'plan_code' => $planCode,
                        'feature_code' => $code,
                        'name' => $nameByCode[$code] ?? $code,
                    ];
                }
            }
        }

        return compact('hidden', 'restored');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    private function uniqueFeatureRows(array $plan): array
    {
        $byCode = [];
        foreach (['coverage', 'features', 'limits'] as $section) {
            foreach (is_array($plan[$section] ?? null) ? $plan[$section] : [] as $row) {
                if (! is_array($row) || ! isset($row['code'])) {
                    continue;
                }
                $code = (string) $row['code'];
                if ($code === '' || isset($byCode[$code])) {
                    continue;
                }
                $byCode[$code] = $row;
            }
        }

        return array_values($byCode);
    }
}
