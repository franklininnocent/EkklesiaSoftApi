<?php

namespace Modules\Subscriptions\Support;

use App\Support\MoneyMath;
use Modules\Subscriptions\Models\PlanVersion;

/**
 * Resolves effective subscription tax from platform policy and optional plan-version override.
 */
final class TaxPolicy
{
    public const SOURCE_PLATFORM = 'platform';

    public const SOURCE_PLAN_VERSION = 'plan_version';

    /**
     * @param  array<string, mixed>  $platformTax  merged policies.tax (label, rate_percent, prices_include_tax, …)
     * @return array{label: string, rate_percent: string, prices_include_tax: bool, source: string}
     */
    public static function resolve(?PlanVersion $version, array $platformTax): array
    {
        $platformRate = MoneyMath::normalize((string) ($platformTax['rate_percent'] ?? '0'));
        $platformLabel = (string) ($platformTax['label'] ?? 'Tax');
        $platformInclusive = (bool) ($platformTax['prices_include_tax'] ?? false);

        if ($version !== null && $version->tax_rate_percent !== null) {
            return [
                'label' => $version->tax_label ?: $platformLabel,
                'rate_percent' => MoneyMath::normalize((string) $version->tax_rate_percent),
                'prices_include_tax' => (bool) $version->tax_inclusive,
                'source' => self::SOURCE_PLAN_VERSION,
            ];
        }

        return [
            'label' => $platformLabel,
            'rate_percent' => $platformRate,
            'prices_include_tax' => $platformInclusive,
            'source' => self::SOURCE_PLATFORM,
        ];
    }

    /**
     * @param  array<string, mixed>  $platformTax
     * @return array{label: string, rate_percent: string, prices_include_tax: bool}
     */
    public static function platformOnly(array $platformTax): array
    {
        $resolved = self::resolve(null, $platformTax);

        return [
            'label' => $resolved['label'],
            'rate_percent' => $resolved['rate_percent'],
            'prices_include_tax' => $resolved['prices_include_tax'],
        ];
    }
}
