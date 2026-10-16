<?php

namespace Modules\Subscriptions\Support;

use InvalidArgumentException;

/**
 * Intended launch-plan entitlements (from catalog definition) and the previous install seed
 * used to classify safe drift before publishing aligned plan versions.
 */
final class LaunchPlanIntentMatrix
{
    /** @var list<string> */
    public const LAUNCH_PLAN_CODES = ['STARTER', 'STANDARD', 'PROFESSIONAL', 'ENTERPRISE'];

    /**
     * @return array{features: list<string>, limits: array<string, int|null>}
     */
    public static function intendedFor(string $planCode): array
    {
        $code = strtoupper($planCode);
        foreach (SubscriptionCatalogDefinition::plans() as $definition) {
            if ($definition['code'] === $code) {
                return [
                    'features' => self::sortedCodes($definition['features']),
                    'limits' => self::normalizeLimits($definition['limits'] ?? []),
                ];
            }
        }

        throw new InvalidArgumentException("Unknown launch plan code {$code}.");
    }

    /**
     * Feature matrix shipped before the verified-capabilities alignment (for drift detection).
     *
     * @return array{features: list<string>, limits: array<string, int|null>}
     */
    public static function previousSeedFor(string $planCode): array
    {
        $starter = ['BASIC_REPORTS', 'CONTRIBUTIONS', 'RBAC_BASIC'];
        $standard = array_merge($starter, [
            'CONTRIBUTION_PLANS', 'FINANCIAL_DASHBOARD', 'MINISTRIES', 'RBAC_ADVANCED', 'ADVANCED_REPORTS',
            'CHURCH_LEADERSHIP', 'DIOCESE_BISHOPS', 'AUDIT_LOG', 'IMPORT_EXPORT', 'ADVANCED_DASHBOARDS',
        ]);
        $professional = array_merge($standard, [
            'ADVANCED_FINANCIAL_REPORTING', 'ADVANCED_MINISTRY_MANAGEMENT', 'ADVANCED_ANALYTICS', 'AUTOMATION',
            'ADVANCED_COMMUNICATION', 'MULTIPLE_WORKFLOWS', 'PRIORITY_SUPPORT',
        ]);
        $enterprise = array_merge($professional, [
            'MULTI_CAMPUS', 'DIOCESAN_DEPLOYMENT', 'CHURCH_NETWORKS', 'DEDICATED_ONBOARDING', 'DEDICATED_SUPPORT',
            'CUSTOM_REPORTING', 'SSO', 'ENTERPRISE_INTEGRATIONS', 'API_ACCESS', 'CUSTOM_BRANDING',
        ]);

        $map = [
            'STARTER' => [$starter, ['PEOPLE_LIMIT' => 250, 'STORAGE_MB' => 2048]],
            'STANDARD' => [$standard, ['PEOPLE_LIMIT' => 1000, 'STORAGE_MB' => 10240]],
            'PROFESSIONAL' => [$professional, ['PEOPLE_LIMIT' => 2500, 'STORAGE_MB' => 25600]],
            'ENTERPRISE' => [$enterprise, ['PEOPLE_LIMIT' => null, 'STORAGE_MB' => null]],
        ];

        $code = strtoupper($planCode);
        if (! isset($map[$code])) {
            throw new InvalidArgumentException("Unknown launch plan code {$code}.");
        }

        [$features, $limits] = $map[$code];

        return [
            'features' => self::sortedCodes($features),
            'limits' => self::normalizeLimits($limits),
        ];
    }

    /**
     * @param  list<string>  $enabledBooleanCodes
     * @param  array<string, int|null>  $limits
     * @return array{features: list<string>, limits: array<string, int|null>}
     */
    public static function snapshotFromRuntime(array $enabledBooleanCodes, array $limits): array
    {
        return [
            'features' => self::sortedCodes($enabledBooleanCodes),
            'limits' => self::normalizeLimits($limits),
        ];
    }

    /**
     * @param  array{features: list<string>, limits: array<string, int|null>}  $left
     * @param  array{features: list<string>, limits: array<string, int|null>}  $right
     */
    public static function snapshotsEqual(array $left, array $right): bool
    {
        return $left['features'] === $right['features'] && $left['limits'] === $right['limits'];
    }

    /**
     * @return list<string>
     */
    /**
     * Install-seed pricing/trial for drift reporting (does not drive alignment writes).
     *
     * @return array{monthly_price: string|null, annual_price: string|null, trial_days: int|null}
     */
    public static function seedPricingFor(string $planCode): array
    {
        foreach (SubscriptionCatalogDefinition::plans() as $definition) {
            if ($definition['code'] === strtoupper($planCode)) {
                $version = $definition['version'] ?? [];

                return [
                    'monthly_price' => $version['monthly_price'] ?? null,
                    'annual_price' => $version['annual_price'] ?? null,
                    'trial_days' => $version['trial_days'] ?? null,
                ];
            }
        }

        throw new InvalidArgumentException('Unknown launch plan code '.$planCode.'.');
    }

    /**
     * @return list<string>
     */
    public static function internalCatalogFeatureCodes(): array
    {
        return [
            'ADVANCED_DASHBOARDS',
            'ADVANCED_ANALYTICS',
            'CUSTOM_REPORTING',
            'MULTIPLE_WORKFLOWS',
            'AUTOMATION',
            'ADVANCED_COMMUNICATION',
            'PRIORITY_SUPPORT',
            'DEDICATED_SUPPORT',
            'DEDICATED_ONBOARDING',
            'MULTI_CAMPUS',
            'DIOCESAN_DEPLOYMENT',
            'CHURCH_NETWORKS',
            'SSO',
            'ENTERPRISE_INTEGRATIONS',
            'API_ACCESS',
            'CUSTOM_BRANDING',
        ];
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private static function sortedCodes(array $codes): array
    {
        $normalized = array_values(array_unique(array_map(static fn (string $c) => strtoupper($c), $codes)));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, int|null>  $limits
     * @return array<string, int|null>
     */
    private static function normalizeLimits(array $limits): array
    {
        $out = [];
        foreach (['PEOPLE_LIMIT', 'STORAGE_MB'] as $key) {
            if (! array_key_exists($key, $limits)) {
                continue;
            }
            $value = $limits[$key];
            $out[$key] = $value === null ? null : (int) $value;
        }

        return $out;
    }
}
