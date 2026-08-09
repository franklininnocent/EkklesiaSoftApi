<?php

namespace Modules\MinistriesAssociations\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Activity window for platform Ministries Insights aggregations.
 */
final class AdminMinistriesWindow
{
    /** @var list<int> */
    public const ALLOWED_DAYS = [7, 30, 90, 180, 365];

    public const MAX_CUSTOM_RANGE_DAYS = 366;

    public function __construct(
        public readonly int $days,
        public readonly CarbonImmutable $currentStart,
        public readonly CarbonImmutable $currentEnd,
        public readonly CarbonImmutable $priorStart,
        public readonly CarbonImmutable $priorEnd,
    ) {
    }

    public static function fromDays(?int $days, ?CarbonImmutable $now = null): self
    {
        $resolved = $days ?? AdminMinistriesDefinitions::DEFAULT_ACTIVITY_WINDOW_DAYS;
        if (! in_array($resolved, self::ALLOWED_DAYS, true)) {
            throw new InvalidArgumentException('window_days must be one of: 7, 30, 90, 180, 365.');
        }

        $end = $now ?? CarbonImmutable::now('UTC');
        $currentStart = $end->subDays($resolved);
        $priorEnd = $currentStart;
        $priorStart = $priorEnd->subDays($resolved);

        return new self($resolved, $currentStart, $end, $priorStart, $priorEnd);
    }

    public static function fromRange(
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): self {
        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('date_to must be on or after date_from.');
        }

        $days = (int) $start->diffInDays($end);
        if ($days > self::MAX_CUSTOM_RANGE_DAYS) {
            throw new InvalidArgumentException(
                'Custom date range cannot exceed '.self::MAX_CUSTOM_RANGE_DAYS.' days.'
            );
        }

        $spanDays = max(1, $days);
        $priorEnd = $start;
        $priorStart = $priorEnd->subDays($spanDays);

        return new self($spanDays, $start, $end, $priorStart, $priorEnd);
    }
}
