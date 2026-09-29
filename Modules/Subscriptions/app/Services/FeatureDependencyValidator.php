<?php

namespace Modules\Subscriptions\Services;

use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;

/**
 * Validates entitlement sets and dependency graphs against the feature catalog.
 */
class FeatureDependencyValidator
{
    public function __construct(private readonly EntitlementCatalog $catalog) {}

    /**
     * Enabled features whose required features are not enabled.
     *
     * @param  list<string>  $enabledCodes
     * @return array<string, list<string>> code => missing required codes
     */
    public function missingDependencies(array $enabledCodes): array
    {
        $enabled = array_flip(array_map('strtoupper', $enabledCodes));
        foreach ($this->catalog->features() as $code => $feature) {
            if ($feature['is_core']) {
                $enabled[$code] = true;
            }
        }

        $missing = [];
        foreach (array_keys($enabled) as $code) {
            $absent = array_values(array_filter(
                $this->catalog->dependenciesOf((string) $code),
                static fn (string $required) => ! isset($enabled[$required])
            ));
            if ($absent !== []) {
                $missing[(string) $code] = $absent;
            }
        }

        return $missing;
    }

    /**
     * Whether making $code depend on $requires would create a cycle.
     *
     * @param  list<string>  $requires
     * @param  array<string, list<string>>|null  $graph
     */
    public function createsCycle(string $code, array $requires, ?array $graph = null): bool
    {
        $graph ??= $this->catalog->snapshot()['dependencies'];
        $graph[$code] = $requires;

        $visiting = [];
        $visited = [];
        $visit = function (string $node) use (&$visit, &$visiting, &$visited, $graph): bool {
            if (isset($visiting[$node])) {
                return true;
            }
            if (isset($visited[$node])) {
                return false;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                if ($visit($next)) {
                    return true;
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;

            return false;
        };

        return $visit($code);
    }
}
