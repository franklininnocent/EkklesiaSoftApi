<?php

namespace Modules\Tenants\Support;

/**
 * Resolved church/tenant display currency (not SaaS plan billing currency).
 */
final class ChurchCurrency
{
    public function __construct(
        public readonly ?string $countryCode,
        public readonly ?string $currencyCode,
        public readonly ?string $currencySymbol,
        public readonly ?string $currencyName,
        public readonly int $decimalDigits,
        public readonly string $locale,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function toArray(): ?array
    {
        if ($this->currencyCode === null || $this->currencyCode === '') {
            return null;
        }

        return [
            'country_code' => $this->countryCode,
            'currency_code' => $this->currencyCode,
            'currency_symbol' => $this->currencySymbol,
            'currency_name' => $this->currencyName,
            'decimal_digits' => $this->decimalDigits,
            'locale' => $this->locale,
        ];
    }

    public function currencyCodeOrNull(): ?string
    {
        $code = $this->currencyCode;

        return $code !== null && $code !== '' ? strtoupper($code) : null;
    }
}
