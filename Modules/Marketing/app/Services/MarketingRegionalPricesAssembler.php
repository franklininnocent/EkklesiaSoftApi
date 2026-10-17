<?php

namespace Modules\Marketing\Services;

use App\Support\MoneyMath;
use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanCatalogPrice;
use Modules\Subscriptions\Models\PlanVersion;

/**
 * Builds marketing regional_prices from plan_catalog_prices (IN / US / BR rows). No FX.
 * INR falls back to the active plan version only when no IN catalog row exists.
 */
class MarketingRegionalPricesAssembler
{
    /** @var array<string, string> ISO currency → canonical plan_catalog_prices.country_code */
    public const CURRENCY_CATALOG_COUNTRY = [
        'INR' => 'IN',
        'USD' => 'US',
        'BRL' => 'BR',
    ];

    /** @var list<string> */
    public const MARKETING_CURRENCIES = ['INR', 'USD', 'BRL'];

    /**
     * @return array{
     *   regional_prices: array<string, array{monthly_price: string|null, annual_price: string|null}>,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public function assemble(Plan $plan, PlanVersion $version): array
    {
        $code = (string) $plan->code;
        $errors = [];
        $warnings = [];
        $regional = [];

        $catalogByCountry = PlanCatalogPrice::query()
            ->where('plan_id', $plan->id)
            ->get()
            ->keyBy(fn (PlanCatalogPrice $row) => strtoupper((string) $row->country_code));

        foreach (self::MARKETING_CURRENCIES as $currency) {
            $result = $this->resolveCurrency($code, $currency, $version, $catalogByCountry);
            $errors = array_merge($errors, $result['errors']);
            if ($result['entry'] !== null) {
                $regional[$currency] = $result['entry'];
            }
        }

        if ($plan->pricing_type === Plan::PRICING_FIXED) {
            foreach (self::MARKETING_CURRENCIES as $currency) {
                $entry = $regional[$currency] ?? null;
                if ($entry === null || ($entry['monthly_price'] === null && $entry['annual_price'] === null)) {
                    $warnings[] = "{$code}: missing plan_catalog_prices row for marketing currency {$currency}.";
                } elseif ($entry['monthly_price'] === null) {
                    $warnings[] = "{$code}: missing {$currency} monthly price in plan_catalog_prices.";
                }
            }
        }

        return [
            'regional_prices' => $regional,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, PlanCatalogPrice>  $catalogByCountry
     * @return array{
     *   entry: array{monthly_price: string|null, annual_price: string|null}|null,
     *   errors: list<string>
     * }
     */
    private function resolveCurrency(
        string $planCode,
        string $currency,
        PlanVersion $version,
        $catalogByCountry,
    ): array {
        $errors = [];
        $countryCode = self::CURRENCY_CATALOG_COUNTRY[$currency] ?? null;
        if ($countryCode === null) {
            return ['entry' => null, 'errors' => []];
        }

        $row = $catalogByCountry->get($countryCode);
        if ($row !== null) {
            $rowCurrency = strtoupper((string) $row->currency_code);
            if ($rowCurrency !== $currency) {
                $errors[] = "{$planCode}: plan_catalog_prices {$countryCode} row currency {$rowCurrency} does not match expected {$currency}.";

                return ['entry' => null, 'errors' => $errors];
            }

            $monthly = $this->normalizeAmount($row->monthly_price);
            $annual = $this->normalizeAmount($row->annual_price);

            if ($currency === 'INR') {
                $errors = array_merge($errors, $this->assertVersionMatchesInrCatalog($planCode, $version, $monthly, $annual));
            }

            if ($errors !== []) {
                return ['entry' => null, 'errors' => $errors];
            }

            if ($monthly === null && $annual === null) {
                return ['entry' => null, 'errors' => []];
            }

            return [
                'entry' => [
                    'monthly_price' => $monthly,
                    'annual_price' => $annual,
                ],
                'errors' => [],
            ];
        }

        if ($currency === 'INR' && strtoupper((string) $version->currency_code) === 'INR') {
            $monthly = $this->normalizeAmount($version->monthly_price);
            $annual = $this->normalizeAmount($version->annual_price);
            if ($monthly === null && $annual === null) {
                return ['entry' => null, 'errors' => []];
            }

            return [
                'entry' => [
                    'monthly_price' => $monthly,
                    'annual_price' => $annual,
                ],
                'errors' => [],
            ];
        }

        return ['entry' => null, 'errors' => []];
    }

    /**
     * @return list<string>
     */
    private function assertVersionMatchesInrCatalog(
        string $planCode,
        PlanVersion $version,
        ?string $catalogMonthly,
        ?string $catalogAnnual,
    ): array {
        if (strtoupper((string) $version->currency_code) !== 'INR') {
            return [];
        }

        $errors = [];
        $versionMonthly = $this->normalizeAmount($version->monthly_price);
        $versionAnnual = $this->normalizeAmount($version->annual_price);

        if ($catalogMonthly !== null && $versionMonthly !== null && ! MoneyMath::equals($catalogMonthly, $versionMonthly)) {
            $errors[] = "{$planCode}: plan_catalog_prices IN monthly ({$catalogMonthly}) disagrees with plan version ({$versionMonthly}).";
        }
        if ($catalogAnnual !== null && $versionAnnual !== null && ! MoneyMath::equals($catalogAnnual, $versionAnnual)) {
            $errors[] = "{$planCode}: plan_catalog_prices IN annual ({$catalogAnnual}) disagrees with plan version ({$versionAnnual}).";
        }

        return $errors;
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return MoneyMath::normalize((string) $value);
    }
}
