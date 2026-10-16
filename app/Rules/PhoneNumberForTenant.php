<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

class PhoneNumberForTenant implements ValidationRule
{
    public function __construct(
        protected ?string $overrideCountry = null
    ) {}

    /**
     * Get the tenant's country ISO2 code from multiple sources.
     * Priority:
     * 1. Override (if provided)
     * 2. Effective tenant official address country ISO2 (home tenant or support session)
     * 3. Default to 'US'
     */
    protected function getTenantCountryCode(?Request $request = null): string
    {
        // Priority 1: Override
        if ($this->overrideCountry) {
            return strtoupper($this->overrideCountry);
        }

        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId !== null) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant) {
                $officialAddress = $tenant->officialAddress()->first();
                if ($officialAddress && $officialAddress->country_id) {
                    $countryModel = $officialAddress->country()->first();
                    if ($countryModel && isset($countryModel->iso2) && $countryModel->iso2) {
                        return strtoupper($countryModel->iso2);
                    }
                }
            }
        }

        return 'US';
    }

    /**
     * Validate the attribute.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Handle null, empty string, or falsy values - these are allowed for nullable fields
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return; // let required handle empties, null is valid for nullable fields
        }

        $raw = trim((string) $value);
        // Double-check after trimming (in case value was whitespace only)
        if ($raw === '' || $raw === 'null') {
            return; // Empty or string "null" should be treated as empty
        }

        $country = $this->getTenantCountryCode();
        $util = PhoneNumberUtil::getInstance();
        
        try {
            $numberProto = $util->parse($raw, $country);
            if (!$util->isValidNumberForRegion($numberProto, $country)) {
                $fail(__('The :attribute must be a valid phone number for :country.', [
                    'country' => $country,
                ]));
            }
        } catch (NumberParseException $e) {
            $fail(__('The :attribute must be a valid phone number.'));
        }
    }
}
