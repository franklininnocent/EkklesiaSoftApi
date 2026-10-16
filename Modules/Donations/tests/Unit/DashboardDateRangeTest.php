<?php

namespace Modules\Donations\Tests\Unit;

use Carbon\Carbon;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Testing\DonationsCertificationTestCase;
use PHPUnit\Framework\Attributes\Test;

class DashboardDateRangeTest extends DonationsCertificationTestCase
{
    #[Test]
    public function omitted_input_returns_null(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);

        $this->assertNull(DashboardDateRange::tryFromInput((int) $ctx['tenant']->id, []));
    }

    #[Test]
    public function this_month_preset_is_month_to_date_with_same_days_prior_month(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = (int) $ctx['tenant']->id;
        $range = DashboardDateRange::tryFromInput($tenantId, ['preset' => DashboardDateRange::PRESET_THIS_MONTH]);

        $this->assertNotNull($range);
        $this->assertSame(DonationBusinessDate::monthStart($tenantId), $range->dateFrom);
        $this->assertSame(DonationBusinessDate::today($tenantId), $range->dateTo);
        $this->assertSame(DashboardDateRange::COMPARISON_SAME_DAYS_PRIOR_MONTH, $range->comparisonMode);
        $this->assertSame('period', $range->metricBasisPayload()['collections']);
        $this->assertSame('point_in_time', $range->metricBasisPayload()['outstanding']);
        $this->assertSame('operational_from_parish_today', $range->metricBasisPayload()['due_next_14_days']);
    }

    #[Test]
    public function ytd_preset_is_calendar_year_start_through_parish_today(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = (int) $ctx['tenant']->id;
        $range = DashboardDateRange::tryFromInput($tenantId, ['preset' => DashboardDateRange::PRESET_YTD]);

        $this->assertNotNull($range);
        $today = DonationBusinessDate::today($tenantId);
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $yearStart = Carbon::createFromFormat('Y-m-d', $today, $timezone)->startOfYear()->toDateString();

        $this->assertSame($yearStart, $range->dateFrom);
        $this->assertSame($today, $range->dateTo);
        $this->assertSame($today, $range->collectionEnd);
        $this->assertSame(DashboardDateRange::PRESET_YTD, $range->preset);
        $this->assertSame(DashboardDateRange::COMPARISON_EQUAL_LENGTH_PRIOR, $range->comparisonMode);
    }

    #[Test]
    public function custom_range_uses_equal_length_prior_window(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $range = DashboardDateRange::resolve(
            (int) $ctx['tenant']->id,
            '2026-01-10',
            '2026-01-12',
            DashboardDateRange::PRESET_CUSTOM
        );

        $this->assertSame('2026-01-07', $range->comparisonStart);
        $this->assertSame('2026-01-09', $range->comparisonEnd);
        $this->assertSame(DashboardDateRange::COMPARISON_EQUAL_LENGTH_PRIOR, $range->comparisonMode);
        $this->assertSame(['start' => '2026-01-07', 'end' => '2026-01-09'], $range->priorEqualLengthWindow());
    }

    #[Test]
    public function future_end_clamps_collection_and_as_of_to_parish_today(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = (int) $ctx['tenant']->id;
        $today = DonationBusinessDate::today($tenantId);
        $future = Carbon::parse($today)->addDays(5)->toDateString();
        $range = DashboardDateRange::resolve(
            $tenantId,
            $today,
            $future,
            DashboardDateRange::PRESET_CUSTOM
        );

        $this->assertSame($today, $range->collectionEnd);
        $this->assertSame($today, $range->asOf);
        $this->assertSame($future, $range->dateTo);
    }

    #[Test]
    public function rejects_inverted_and_overlong_ranges(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = (int) $ctx['tenant']->id;

        $this->expectException(InvalidArgumentException::class);
        DashboardDateRange::resolve($tenantId, '2026-02-01', '2026-01-01', DashboardDateRange::PRESET_CUSTOM);
    }

    #[Test]
    public function rejects_span_over_ten_years(): void
    {
        $ctx = $this->actingAsTenantWith(['donations.view']);
        $tenantId = (int) $ctx['tenant']->id;

        $this->expectException(InvalidArgumentException::class);
        DashboardDateRange::resolve($tenantId, '2010-01-01', '2021-01-02', DashboardDateRange::PRESET_CUSTOM);
    }
}
