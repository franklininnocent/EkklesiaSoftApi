<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;

/**
 * Inclusive parish-calendar range for Overview (and optional report) queries.
 */
final class DashboardDateRange
{
    public const MAX_SPAN_YEARS = 10;

    public const PRESET_THIS_MONTH = 'this_month';

    public const PRESET_THIS_FY = 'this_fy';

    public const PRESET_LAST_90 = 'last_90';

    public const PRESET_YTD = 'ytd';

    public const PRESET_CUSTOM = 'custom';

    public const COMPARISON_SAME_DAYS_PRIOR_MONTH = 'same_days_prior_month';

    public const COMPARISON_EQUAL_LENGTH_PRIOR = 'equal_length_prior';

    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly string $collectionEnd,
        public readonly string $asOf,
        public readonly string $parishToday,
        public readonly string $timezone,
        public readonly string $comparisonStart,
        public readonly string $comparisonEnd,
        public readonly string $comparisonMode,
        public readonly string $preset,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function tryFromInput(int $tenantId, array $input): ?self
    {
        $preset = self::normalizePreset($input['preset'] ?? null);
        $from = self::nullableDateString($input['date_from'] ?? null);
        $to = self::nullableDateString($input['date_to'] ?? null);

        if ($preset === null && $from === null && $to === null) {
            return null;
        }

        return self::resolve($tenantId, $from, $to, $preset);
    }

    public static function resolve(int $tenantId, ?string $dateFrom, ?string $dateTo, ?string $preset): self
    {
        $preset = self::normalizePreset($preset);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $parishToday = DonationBusinessDate::today($tenantId);

        if ($dateFrom === null && $dateTo === null) {
            if ($preset === null || $preset === self::PRESET_CUSTOM) {
                throw new InvalidArgumentException('A date range or a named period is required.');
            }

            return self::forPreset($tenantId, $preset, $timezone, $parishToday);
        }

        if ($dateFrom === null || $dateTo === null) {
            throw new InvalidArgumentException('Both start and end dates are required.');
        }

        self::assertValidDate($dateFrom, 'date_from');
        self::assertValidDate($dateTo, 'date_to');

        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        $fromCarbon = Carbon::createFromFormat('Y-m-d', $dateFrom, $timezone)->startOfDay();
        $toCarbon = Carbon::createFromFormat('Y-m-d', $dateTo, $timezone)->startOfDay();
        if ($fromCarbon->copy()->addYears(self::MAX_SPAN_YEARS)->lt($toCarbon)) {
            throw new InvalidArgumentException('Choose a range of 10 years or less.');
        }

        $effectivePreset = $preset ?? self::PRESET_CUSTOM;
        $isMonthToDate = $effectivePreset === self::PRESET_THIS_MONTH;
        $collectionEnd = $dateTo > $parishToday ? $parishToday : $dateTo;
        $asOf = $collectionEnd;
        [$comparisonStart, $comparisonEnd, $comparisonMode] = self::comparisonWindow(
            $timezone,
            $parishToday,
            $dateFrom,
            $collectionEnd,
            $isMonthToDate
        );

        return new self(
            $dateFrom,
            $dateTo,
            $collectionEnd,
            $asOf,
            $parishToday,
            $timezone,
            $comparisonStart,
            $comparisonEnd,
            $comparisonMode,
            $effectivePreset,
        );
    }

    /**
     * Inclusive days in [dateFrom, collectionEnd].
     */
    public function inclusiveCollectionDays(): int
    {
        $timezone = $this->timezone;
        $start = Carbon::createFromFormat('Y-m-d', $this->dateFrom, $timezone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $this->collectionEnd, $timezone)->startOfDay();

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Equal-length window immediately before dateFrom (for participation delta).
     *
     * @return array{start: string, end: string}
     */
    public function priorEqualLengthWindow(): array
    {
        $days = $this->inclusiveCollectionDays();
        $timezone = $this->timezone;
        $priorEnd = Carbon::createFromFormat('Y-m-d', $this->dateFrom, $timezone)->subDay();
        $priorStart = $priorEnd->copy()->subDays(max(0, $days - 1));

        return [
            'start' => $priorStart->toDateString(),
            'end' => $priorEnd->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function appliedRangePayload(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'collection_end' => $this->collectionEnd,
            'as_of' => $this->asOf,
            'parish_today' => $this->parishToday,
            'timezone' => $this->timezone,
            'preset' => $this->preset,
            'comparison_start' => $this->comparisonStart,
            'comparison_end' => $this->comparisonEnd,
            'comparison_mode' => $this->comparisonMode,
            'inclusive' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function metricBasisPayload(): array
    {
        return [
            'collections' => 'period',
            'giving_mix' => 'period',
            'participation' => 'period',
            'collection_trend' => 'period',
            'outstanding' => 'point_in_time',
            'overdue' => 'point_in_time',
            'project_installments' => 'point_in_time',
            'due_next_14_days' => 'operational_from_parish_today',
        ];
    }

    /**
     * Named preset windows on the parish clock (for UI pills).
     *
     * @return array<string, array{date_from: string, date_to: string}>
     */
    public static function presetWindowsForTenant(int $tenantId): array
    {
        $today = DonationBusinessDate::today($tenantId);
        $fy = app(ChurchFinancialPeriodResolver::class)->currentFiscalYear($tenantId, $today);

        return [
            self::PRESET_THIS_MONTH => [
                'date_from' => DonationBusinessDate::monthStart($tenantId),
                'date_to' => $today,
            ],
            self::PRESET_THIS_FY => [
                'date_from' => $fy->start,
                'date_to' => $today,
            ],
            self::PRESET_LAST_90 => [
                'date_from' => DonationBusinessDate::subDays($tenantId, 89),
                'date_to' => $today,
            ],
            self::PRESET_YTD => [
                'date_from' => Carbon::createFromFormat('Y-m-d', $today, DonationBusinessDate::timezoneForTenant($tenantId))
                    ->startOfYear()
                    ->toDateString(),
                'date_to' => $today,
            ],
        ];
    }

    private static function forPreset(int $tenantId, string $preset, string $timezone, string $parishToday): self
    {
        $windows = self::presetWindowsForTenant($tenantId);
        if (! isset($windows[$preset])) {
            throw new InvalidArgumentException('Unknown period.');
        }

        return self::resolve(
            $tenantId,
            $windows[$preset]['date_from'],
            $windows[$preset]['date_to'],
            $preset
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function comparisonWindow(
        string $timezone,
        string $parishToday,
        string $dateFrom,
        string $collectionEnd,
        bool $isMonthToDate
    ): array {
        if ($isMonthToDate) {
            $current = Carbon::parse($parishToday, $timezone);
            $previousMonth = $current->copy()->subMonth();
            $endDay = min($current->day, $previousMonth->daysInMonth);

            return [
                $previousMonth->copy()->startOfMonth()->toDateString(),
                $previousMonth->copy()->day($endDay)->toDateString(),
                self::COMPARISON_SAME_DAYS_PRIOR_MONTH,
            ];
        }

        $start = Carbon::createFromFormat('Y-m-d', $dateFrom, $timezone)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $collectionEnd, $timezone)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $priorEnd = $start->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays(max(0, $days - 1));

        return [$priorStart->toDateString(), $priorEnd->toDateString(), self::COMPARISON_EQUAL_LENGTH_PRIOR];
    }

    private static function normalizePreset(mixed $preset): ?string
    {
        if (! is_string($preset) || $preset === '') {
            return null;
        }

        $allowed = [
            self::PRESET_THIS_MONTH,
            self::PRESET_THIS_FY,
            self::PRESET_LAST_90,
            self::PRESET_YTD,
            self::PRESET_CUSTOM,
        ];

        return in_array($preset, $allowed, true) ? $preset : null;
    }

    private static function nullableDateString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private static function assertValidDate(string $value, string $field): void
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The {$field} must be a valid date.");
        }
    }
}
