<?php

namespace Modules\Family\Tests\Unit;

use Carbon\Carbon;
use Modules\Family\app\Support\ParishCalendar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MemberCelebrationsServiceTest extends TestCase
{
    #[Test]
    public function it_lists_each_day_in_a_week_range(): void
    {
        $start = Carbon::parse('2026-12-29')->startOfDay();
        $end = Carbon::parse('2027-01-04')->endOfDay();
        $days = ParishCalendar::weekDayOccurrences($start, $end);

        $this->assertCount(7, $days);
        $this->assertSame(12, $days[0]['month']);
        $this->assertSame(29, $days[0]['day']);
        $this->assertSame(1, $days[6]['month']);
        $this->assertSame(4, $days[6]['day']);
    }

    #[Test]
    public function it_labels_week_range_for_display(): void
    {
        $start = Carbon::parse('2026-12-29');
        $end = Carbon::parse('2027-01-04');

        $this->assertSame('Dec 29 – Jan 4', ParishCalendar::weekRangeLabel($start, $end));
    }
}
