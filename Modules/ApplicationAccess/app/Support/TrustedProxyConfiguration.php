<?php

namespace Modules\ApplicationAccess\Support;

/**
 * Parses TRUSTED_PROXIES env — never returns '*' (fail closed).
 */
final class TrustedProxyConfiguration
{
    /**
     * @return list<string>
     */
    public static function proxies(): array
    {
        $raw = (string) env('TRUSTED_PROXIES', '');
        if ($raw === '') {
            return [];
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return array_values(array_filter(
            $proxies,
            fn (string $proxy): bool => $proxy !== '' && $proxy !== '*'
        ));
    }
}
