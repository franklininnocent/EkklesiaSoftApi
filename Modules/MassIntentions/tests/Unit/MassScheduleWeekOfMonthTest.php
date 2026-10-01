<?php

namespace Modules\MassIntentions\Tests\Unit;

use Carbon\Carbon;
use Modules\MassIntentions\Support\MassScheduleWeekOfMonth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MassScheduleWeekOfMonthTest extends TestCase
{
    #[Test]
    public function fourth_and_last_on_same_sunday_match_once(): void
    {
        $date = Carbon::parse('2026-09-27'); // 4th and last Sunday in Sep 2026
        $this->assertSame(4, MassScheduleWeekOfMonth::occurrenceIndexOnWeekday($date));
        $this->assertTrue(MassScheduleWeekOfMonth::isLastWeekdayOccurrenceInMonth($date));
        $this->assertTrue(MassScheduleWeekOfMonth::matchesDate(['4', 'last'], $date));
    }

    #[Test]
    public function second_and_fourth_sundays_only_in_june(): void
    {
        $second = Carbon::parse('2026-06-14');
        $fourth = Carbon::parse('2026-06-28');
        $first = Carbon::parse('2026-06-07');
        $filter = ['2', '4'];

        $this->assertTrue(MassScheduleWeekOfMonth::matchesDate($filter, $second));
        $this->assertTrue(MassScheduleWeekOfMonth::matchesDate($filter, $fourth));
        $this->assertFalse(MassScheduleWeekOfMonth::matchesDate($filter, $first));
    }
}
