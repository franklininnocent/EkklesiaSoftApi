<?php

namespace Modules\Subscriptions\Support;

use Modules\Subscriptions\Models\PlanVersion;
use Modules\Tenants\Services\SubscriptionService;

/**
 * "Contracted Subscription Revenue" — what churches have agreed to pay, not cash received
 * (EkklesiaSoft does not take payments). See docs/platform/subscriptions-entitlements.md.
 *
 * Monthly equivalent of a contracted price:
 *   MONTHLY → price, ANNUAL → price ÷ 12, CUSTOM (agreed yearly amount) → price ÷ 12, NONE → not counted.
 * Counted lifecycle statuses: ACTIVE, EXPIRING, GRACE_PERIOD, LIFETIME (only when priced).
 * Excluded: TRIAL, EXPIRED, SUSPENDED, deleted churches, and subscriptions without a price.
 * ARR = MRR × 12. Sums are kept at 6 decimals and rounded half-up to 2 once.
 */
final class RevenueCalculator
{
    public const LABEL = 'Contracted Subscription Revenue';

    private const SCALE = 6;

    public const COUNTED_STATUSES = [
        SubscriptionService::STATUS_ACTIVE,
        SubscriptionService::STATUS_EXPIRING,
        SubscriptionService::STATUS_GRACE_PERIOD,
        SubscriptionService::STATUS_LIFETIME,
    ];

    /** Unrounded monthly equivalent, or null when the subscription carries no countable price. */
    public static function monthlyEquivalent(?string $contractedPrice, ?string $interval): ?string
    {
        if ($contractedPrice === null || $contractedPrice === '' || ! is_numeric($contractedPrice)) {
            return null;
        }
        $price = bcadd($contractedPrice, '0', self::SCALE);

        return match (strtoupper((string) $interval)) {
            PlanVersion::INTERVAL_MONTHLY => $price,
            PlanVersion::INTERVAL_ANNUAL, PlanVersion::INTERVAL_CUSTOM => bcdiv($price, '12', self::SCALE),
            default => null,
        };
    }

    public static function counts(string $lifecycleStatus): bool
    {
        return in_array($lifecycleStatus, self::COUNTED_STATUSES, true);
    }

    public static function add(string $total, string $amount): string
    {
        return bcadd($total, $amount, self::SCALE);
    }

    public static function round(string $value): string
    {
        $offset = bccomp($value, '0', self::SCALE) < 0 ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $offset, self::SCALE), '0', 2);
    }

    public static function annualize(string $unroundedMrr): string
    {
        return self::round(bcmul($unroundedMrr, '12', self::SCALE));
    }
}
