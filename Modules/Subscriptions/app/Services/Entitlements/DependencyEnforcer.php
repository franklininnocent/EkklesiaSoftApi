<?php

namespace Modules\Subscriptions\Services\Entitlements;

/**
 * Disables features whose required features are not enabled (transitively).
 * Core features are never disabled.
 */
final class DependencyEnforcer
{
    /**
     * @param  array<string, array{enabled: bool, value: int|null, tier: string|null, type: string, source: string}>  $features
     * @return array<string, array{enabled: bool, value: int|null, tier: string|null, type: string, source: string}>
     */
    public static function apply(array $features, EntitlementCatalog $catalog): array
    {
        $changed = true;
        $guard = 0;
        while ($changed && $guard++ < 16) {
            $changed = false;
            foreach ($features as $code => $entry) {
                if (! $entry['enabled'] || ($catalog->feature($code)['is_core'] ?? false)) {
                    continue;
                }
                foreach ($catalog->dependenciesOf($code) as $required) {
                    if (! ($features[$required]['enabled'] ?? false)) {
                        $features[$code]['enabled'] = false;
                        $features[$code]['source'] = 'dependency';
                        $changed = true;
                        break;
                    }
                }
            }
        }

        return $features;
    }
}
