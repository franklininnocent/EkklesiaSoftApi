<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;

class PhoneNumberForTenant implements ValidationRule
{
    public function __construct(
        protected ?string $overrideCountry = null
    ) {}

    /**
     * Get the tenant's country ISO2 code from multiple sources.
     * Priority:
     * 1. Override (if provided)
     * 2. X-Tenant-Country header (sent from frontend)
     * 3. Tenant's official address country ISO2
     * 4. Default to 'US'
     */
    protected function getTenantCountryCode(?Request $request = null): string
    {
        // Priority 1: Override
        if ($this->overrideCountry) {
            return strtoupper($this->overrideCountry);
        }

        // Priority 2: X-Tenant-Country header (sent from frontend)
        $request = $request ?? request();
        if ($request && $request->hasHeader('X-Tenant-Country')) {
            $headerCountry = strtoupper(trim($request->header('X-Tenant-Country')));
            if (strlen($headerCountry) === 2) {
                return $headerCountry;
            }
        }

        // Priority 3: Get from tenant's official address
        $user = auth()->user();
        if ($user && $user->tenant) {
            $tenant = $user->tenant;
            // Load the official address
            $officialAddress = $tenant->officialAddress()->first();
            
            if ($officialAddress && $officialAddress->country_id) {
                // Load the country model via relationship
                $countryModel = $officialAddress->country()->first();
                if ($countryModel && isset($countryModel->iso2) && $countryModel->iso2) {
                    return strtoupper($countryModel->iso2);
                }
            }
        }

        // Priority 4: Default to US
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
