<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Log;
use Modules\Donations\Models\DonationSetting;
use Modules\Tenants\Models\Address;
use Modules\Tenants\Models\Country;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ChurchCurrency;
use Modules\Tenants\Support\ChurchCurrencyLocale;

class ChurchCurrencyResolver
{
    /** @var array<int, ChurchCurrency|null> */
    private array $memo = [];

    public function forTenantId(int $tenantId): ?ChurchCurrency
    {
        if (array_key_exists($tenantId, $this->memo)) {
            return $this->memo[$tenantId];
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            $this->memo[$tenantId] = null;

            return null;
        }

        $country = $this->resolveCountryForTenant($tenant);
        $dto = $this->fromCountry($country);

        if ($dto === null) {
            $fallbackCode = ChurchCurrencyLocale::isValidIso4217($tenant->currency_code)
                ? strtoupper((string) $tenant->currency_code)
                : null;

            if ($fallbackCode !== null) {
                $dto = $this->fromCurrencyCodeOnly($fallbackCode, null);
            } else {
                Log::warning('church_currency.unresolved', ['tenant_id' => $tenantId]);
            }
        }

        $this->memo[$tenantId] = $dto;

        return $dto;
    }

    public function currencyCodeForTenantId(int $tenantId): ?string
    {
        return $this->forTenantId($tenantId)?->currencyCodeOrNull();
    }

    /**
     * Write-through cache on tenant + donation settings (amounts unchanged).
     */
    public function syncDerivedColumns(int $tenantId): void
    {
        $dto = $this->forTenantId($tenantId);
        $code = $dto?->currencyCodeOrNull();

        if ($code === null) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant !== null && (string) $tenant->currency_code !== $code) {
            $tenant->updateQuietly(['currency_code' => $code]);
        }

        $settings = DonationSetting::forTenant($tenantId)->first();
        if ($settings !== null && (string) $settings->default_currency !== $code) {
            $settings->updateQuietly(['default_currency' => $code]);
        }
    }

    private function resolveCountryForTenant(Tenant $tenant): ?Country
    {
        $addresses = $tenant->addresses()
            ->where('active', 1)
            ->whereNotNull('country_id')
            ->with('country')
            ->get();

        $official = $addresses->firstWhere('address_type', 'official');
        if ($official instanceof Address) {
            $country = $official->getRelation('country');
            if ($country instanceof Country) {
                return $country;
            }
        }

        $primary = $addresses->firstWhere('address_type', 'primary');
        if ($primary instanceof Address) {
            $country = $primary->getRelation('country');
            if ($country instanceof Country) {
                return $country;
            }
        }

        $any = $addresses->first();
        if ($any instanceof Address) {
            $country = $any->getRelation('country');
            if ($country instanceof Country) {
                return $country;
            }
        }

        return null;
    }

    private function fromCountry(?Country $country): ?ChurchCurrency
    {
        if ($country === null) {
            return null;
        }

        $code = $country->currency !== null && $country->currency !== ''
            ? strtoupper((string) $country->currency)
            : null;

        if (! ChurchCurrencyLocale::isValidIso4217($code)) {
            return null;
        }

        return new ChurchCurrency(
            countryCode: $country->iso2 ? strtoupper((string) $country->iso2) : null,
            currencyCode: $code,
            currencySymbol: $country->currency_symbol,
            currencyName: $country->currency_name,
            decimalDigits: ChurchCurrencyLocale::decimalDigitsFor($code),
            locale: ChurchCurrencyLocale::localeFor($code),
        );
    }

    private function fromCurrencyCodeOnly(string $code, ?string $countryCode): ChurchCurrency
    {
        $row = Country::query()
            ->where('currency', $code)
            ->where('active', true)
            ->first();

        return new ChurchCurrency(
            countryCode: $countryCode,
            currencyCode: $code,
            currencySymbol: $row?->currency_symbol,
            currencyName: $row?->currency_name,
            decimalDigits: ChurchCurrencyLocale::decimalDigitsFor($code),
            locale: ChurchCurrencyLocale::localeFor($code),
        );
    }
}
