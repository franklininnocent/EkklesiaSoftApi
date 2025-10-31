<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;

class PhoneNumberForTenant implements ValidationRule
{
    public function __construct(
        protected ?string $overrideCountry = null
    ) {}

    /**
     * Validate the attribute.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return; // let required handle empties
        }

        $country = $this->overrideCountry
            ?: (optional(auth()->user()?->tenant)->country_code ?? 'US');
        $country = strtoupper((string) $country);

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
