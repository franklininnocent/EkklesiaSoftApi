<?php

namespace Modules\Marketing\Services;

use Modules\Subscriptions\Models\Plan;

/**
 * Validates marketing plan catalog snapshots before approval.
 */
class MarketingPlanCatalogValidator
{
    /** @var list<string> */
    public const ALLOWED_PLAN_KEYS = [
        'code',
        'name',
        'slug',
        'short_description',
        'badge_label',
        'is_featured',
        'pricing_type',
        'display_order',
        'currency_code',
        'monthly_price',
        'annual_price',
        'setup_fee',
        'billing_intervals',
        'trial_days',
        'tax',
        'features',
        'limits',
        'coverage',
        'version_number',
        'regional_prices',
    ];

    /** @var list<string> */
    public const MARKETING_REGIONAL_CURRENCIES = ['INR', 'USD', 'BRL'];

    /**
     * @param  list<array<string, mixed>>  $plans
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $plans): array
    {
        $errors = [];
        $warnings = [];
        $codes = [];

        if ($plans === []) {
            $warnings[] = 'The catalog contains no plans. Visitors will see an empty pricing section until plans are added.';

            return compact('errors', 'warnings');
        }

        foreach ($plans as $index => $plan) {
            $prefix = 'Plan #'.($index + 1);
            $code = isset($plan['code']) ? (string) $plan['code'] : '';
            if ($code === '') {
                $errors[] = "{$prefix}: missing stable plan code.";

                continue;
            }
            if (isset($codes[$code])) {
                $errors[] = "Duplicate plan code: {$code}.";
            }
            $codes[$code] = true;

            $name = isset($plan['name']) ? trim((string) $plan['name']) : '';
            if ($name === '') {
                $errors[] = "{$code}: missing public plan name.";
            }

            $pricingType = (string) ($plan['pricing_type'] ?? '');
            if (! in_array($pricingType, [Plan::PRICING_FIXED, Plan::PRICING_CUSTOM, Plan::PRICING_FREE], true)) {
                $errors[] = "{$code}: unsupported pricing_type.";
            }

            $currency = isset($plan['currency_code']) ? strtoupper((string) $plan['currency_code']) : '';
            if ($pricingType === Plan::PRICING_FIXED && ($currency === '' || strlen($currency) !== 3)) {
                $errors[] = "{$code}: fixed pricing requires a valid ISO currency_code.";
            }

            if ($pricingType === Plan::PRICING_FIXED) {
                $monthly = $plan['monthly_price'] ?? null;
                $annual = $plan['annual_price'] ?? null;
                if ($monthly === null && $annual === null) {
                    $errors[] = "{$code}: fixed pricing requires at least one price.";
                }
            }

            if ($pricingType === Plan::PRICING_CUSTOM) {
                if (($plan['monthly_price'] ?? null) !== null || ($plan['annual_price'] ?? null) !== null) {
                    $errors[] = "{$code}: custom-priced plans must not expose numeric list prices on the marketing site.";
                }
            }

            foreach (array_keys($plan) as $key) {
                if (! in_array($key, self::ALLOWED_PLAN_KEYS, true)) {
                    $errors[] = "{$code}: unexpected field {$key} in public payload.";
                }
            }

            $this->validateImportExport($plan, $code, $errors);
            $this->validateLimits($plan, $code, $errors);

            if (($plan['short_description'] ?? '') === '') {
                $warnings[] = "{$code}: missing short_description.";
            }

            $this->validateRegionalPrices($plan, $code, $errors);
        }

        $orders = array_map(static fn (array $p) => (int) ($p['display_order'] ?? 0), $plans);
        if (count(array_unique($orders)) !== count($orders)) {
            $warnings[] = 'Multiple plans share the same display_order; sort order may be ambiguous.';
        }

        return compact('errors', 'warnings');
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateImportExport(array $plan, string $code, array &$errors): void
    {
        foreach (['features', 'coverage'] as $section) {
            $rows = is_array($plan[$section] ?? null) ? $plan[$section] : [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (($row['code'] ?? '') !== 'IMPORT_EXPORT') {
                    continue;
                }
                $name = (string) ($row['name'] ?? '');
                if (stripos($name, 'import') !== false && stripos($name, 'export') === false) {
                    $errors[] = "{$code}: IMPORT_EXPORT must be presented as Church Data Export, not Import.";
                }
                $desc = strtolower((string) ($row['description'] ?? ''));
                if (str_contains($desc, 'import and export') || (str_contains($desc, 'import') && ! str_contains($desc, 'export'))) {
                    $errors[] = "{$code}: IMPORT_EXPORT description must not advertise bulk import.";
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateRegionalPrices(array $plan, string $code, array &$errors): void
    {
        if (! array_key_exists('regional_prices', $plan)) {
            return;
        }

        $regional = $plan['regional_prices'];
        if (! is_array($regional)) {
            $errors[] = "{$code}: regional_prices must be an object.";

            return;
        }

        foreach ($regional as $currency => $entry) {
            $currencyKey = strtoupper((string) $currency);
            if (! in_array($currencyKey, self::MARKETING_REGIONAL_CURRENCIES, true)) {
                $errors[] = "{$code}: unexpected regional currency {$currencyKey}.";

                continue;
            }
            if (! is_array($entry)) {
                $errors[] = "{$code}: regional_prices.{$currencyKey} must be an object.";

                continue;
            }
            foreach (['monthly_price', 'annual_price'] as $priceKey) {
                if (! array_key_exists($priceKey, $entry)) {
                    $errors[] = "{$code}: regional_prices.{$currencyKey} missing {$priceKey}.";

                    continue;
                }
                $value = $entry[$priceKey];
                if ($value !== null && ! is_string($value)) {
                    $errors[] = "{$code}: regional_prices.{$currencyKey}.{$priceKey} must be a decimal string or null.";
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateLimits(array $plan, string $code, array &$errors): void
    {
        $limits = is_array($plan['limits'] ?? null) ? $plan['limits'] : [];
        foreach ($limits as $limit) {
            if (! is_array($limit)) {
                continue;
            }
            $unlimited = (bool) ($limit['unlimited'] ?? false);
            $value = $limit['value'] ?? null;
            if ($unlimited && $value !== null) {
                $errors[] = "{$code}: limit {$limit['code']} cannot be unlimited and have a numeric value.";
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function allowlistPlan(array $plan): array
    {
        $out = [];
        foreach (self::ALLOWED_PLAN_KEYS as $key) {
            if (array_key_exists($key, $plan)) {
                $out[$key] = $plan[$key];
            }
        }

        return $out;
    }
}
