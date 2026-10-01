<?php

namespace Tests\Unit\Support;

use App\Support\UserFacingDate;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserFacingDateTest extends TestCase
{
    #[Test]
    public function it_formats_calendar_dates_day_first(): void
    {
        $this->assertSame('30 Sep 2026', UserFacingDate::formatDate('2026-09-30'));
        $this->assertSame('5 Jan 2026', UserFacingDate::formatDate('2026-01-05'));
    }

    #[Test]
    public function it_formats_date_times_without_seconds(): void
    {
        $this->assertSame(
            '30 Sep 2026, 9:59 AM',
            UserFacingDate::formatDateTime(Carbon::parse('2026-09-30 09:59:00')),
        );
        $this->assertSame(
            '30 Sep 2026, 9:59 PM',
            UserFacingDate::formatDateTime(Carbon::parse('2026-09-30 21:59:00')),
        );
    }

    #[Test]
    public function it_formats_month_year_and_day_month_labels(): void
    {
        $this->assertSame('Sep 2026', UserFacingDate::formatMonthYear('2026-09-30'));
        $this->assertSame('30 Sep', UserFacingDate::formatDayMonth('2026-09-30'));
    }

    #[Test]
    public function it_returns_empty_for_missing_values(): void
    {
        $this->assertSame('', UserFacingDate::formatDate(null));
        $this->assertSame('', UserFacingDate::formatDateTime(''));
    }
}
