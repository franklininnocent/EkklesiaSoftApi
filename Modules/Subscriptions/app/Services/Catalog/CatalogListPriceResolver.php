<?php

namespace Modules\Subscriptions\Services\Catalog;

use Modules\Subscriptions\Models\Plan;
use Modules\Subscriptions\Models\PlanCatalogPrice;
use Modules\Subscriptions\Models\PlanVersion;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\ChurchCurrencyResolver;

/**
 * Picks the billed catalog list price for a tenant from official-address country.
 * Falls back to the plan version's own currency_code and amounts (no FX).
 */
class CatalogListPriceResolver
{
    public function __construct(private readonly ChurchCurrencyResolver $churchCurrency) {}

    /**
     * @return array{
     *   currency_code: string,
     *   monthly_price: string|null,
     *   annual_price: string|null,
     *   uses_regional_price: bool
     * }
     */
    public function billedTerms(Plan $plan, PlanVersion $version, ?Tenant $tenant): array
    {
        $default = [
            'currency_code' => strtoupper((string) $version->currency_code),
            'monthly_price' => $this->decimal($version->monthly_price),
            'annual_price' => $this->decimal($version->annual_price),
            'uses_regional_price' => false,
        ];

        if ($tenant === null) {
            return $default;
        }

        $countryCode = $this->churchCurrency->forTenantId((int) $tenant->id)?->countryCode;
        if ($countryCode === null || $countryCode === '') {
            return $default;
        }

        $row = PlanCatalogPrice::query()
            ->where('plan_id', $plan->id)
            ->where('country_code', strtoupper($countryCode))
            ->first();

        if ($row === null) {
            return $default;
        }

        return [
            'currency_code' => strtoupper((string) $row->currency_code),
            'monthly_price' => $this->decimal($row->monthly_price),
            'annual_price' => $this->decimal($row->annual_price),
            'uses_regional_price' => true,
        ];
    }

    private function decimal(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
