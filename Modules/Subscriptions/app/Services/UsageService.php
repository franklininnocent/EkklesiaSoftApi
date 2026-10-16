<?php

namespace Modules\Subscriptions\Services;

use Modules\Subscriptions\Services\Entitlements\EntitlementCatalog;
use Modules\Subscriptions\Services\Entitlements\ResolvedEntitlements;
use Modules\Tenants\Support\UsageMetricRegistry;

/**
 * Current tenant usage for limit features, measured by providers registered by owning modules.
 */
class UsageService
{
    public const LEVEL_OK = 'ok';

    public const LEVEL_NOTICE = 'notice';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    public const LEVEL_AT_LIMIT = 'at_limit';

    public const LEVEL_OVER_LIMIT = 'over_limit';

    public function __construct(
        private readonly UsageMetricRegistry $registry,
        private readonly EntitlementCatalog $catalog,
        private readonly SubscriptionPolicyService $policies,
    ) {}

    public function isMeasurable(string $code): bool
    {
        return $this->registry->has($code);
    }

    public function current(int $tenantId, string $code): ?int
    {
        $provider = $this->registry->get($code);

        return $provider ? max(0, $provider->currentUsage($tenantId)) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summary(ResolvedEntitlements $resolved): array
    {
        $rows = [];
        foreach ($this->catalog->features() as $code => $feature) {
            if (! in_array($feature['type'], ['LIMIT', 'QUOTA', 'USAGE'], true) || ! $this->isMeasurable($code)) {
                continue;
            }
            $limit = $resolved->limit($code);
            $usage = (int) $this->current($resolved->tenantId, $code);
            $rows[] = $this->row($code, $feature, $usage, $limit);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>
     */
    public function row(string $code, array $feature, int $usage, ?int $limit): array
    {
        $percent = $limit !== null && $limit > 0 ? round(($usage / $limit) * 100, 1) : ($limit === 0 && $usage > 0 ? 100.0 : null);

        return [
            'code' => $code,
            'name' => $feature['name'],
            'unit' => $feature['unit'],
            'usage' => $usage,
            'limit' => $limit,
            'unlimited' => $limit === null,
            'remaining' => $limit === null ? null : max(0, $limit - $usage),
            'percent_used' => $percent,
            'level' => $this->level($usage, $limit),
        ];
    }

    public function level(int $usage, ?int $limit): string
    {
        if ($limit === null) {
            return self::LEVEL_OK;
        }
        if ($usage > $limit) {
            return self::LEVEL_OVER_LIMIT;
        }
        if ($usage === $limit) {
            return self::LEVEL_AT_LIMIT;
        }
        if ($limit === 0) {
            return self::LEVEL_OK;
        }

        $percent = ($usage / $limit) * 100;
        $thresholds = array_values(array_filter(
            array_map('intval', (array) $this->policies->get('usage_thresholds', [70, 85, 95, 100])),
            static fn (int $t) => $t < 100
        ));
        sort($thresholds);
        $levels = [self::LEVEL_NOTICE, self::LEVEL_WARNING, self::LEVEL_CRITICAL];

        $reached = self::LEVEL_OK;
        foreach ($thresholds as $i => $threshold) {
            if ($percent >= $threshold) {
                $reached = $levels[min($i, count($levels) - 1)];
            }
        }

        return $reached;
    }
}
