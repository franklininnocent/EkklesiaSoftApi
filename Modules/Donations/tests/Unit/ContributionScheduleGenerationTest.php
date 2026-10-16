<?php

namespace Modules\Donations\Tests\Unit;

use InvalidArgumentException;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Support\ContributionPeriod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContributionScheduleGenerationTest extends TestCase
{
    #[Test]
    public function monthly_plan_generates_twelve_periods_for_calendar_year(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-12-31');

        $this->assertCount(12, $periods);
        $this->assertSame('2026-01', $periods[0]['period_label']);
        $this->assertSame('2026-12', $periods[11]['period_label']);
    }

    #[Test]
    public function mid_month_start_still_includes_january_bucket(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'monthly',
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-15', '2026-03-31');

        $this->assertSame('2026-01', $periods[0]['period_label']);
        $this->assertCount(3, $periods);
    }

    #[Test]
    public function one_time_returns_single_period(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'one_time',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan);

        $this->assertCount(1, $periods);
        $this->assertSame('ONCE-20260601', $periods[0]['period_label']);
    }

    #[Test]
    public function quarterly_plan_generates_four_periods_for_calendar_year(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'quarterly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-12-31');

        $this->assertCount(4, $periods);
        $this->assertSame('2026-Q1', $periods[0]['period_label']);
        $this->assertSame('2026-Q4', $periods[3]['period_label']);
    }

    #[Test]
    public function yearly_plan_generates_single_period_for_calendar_year(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'yearly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-12-31');

        $this->assertCount(1, $periods);
        $this->assertSame('2026', $periods[0]['period_label']);
    }

    #[Test]
    public function unknown_frequency_throws(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'biweekly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->expectException(InvalidArgumentException::class);
        ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-12-31');
    }

    #[Test]
    public function end_before_start_returns_empty_period_list(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'monthly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-05-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-06-01', '2026-05-31');

        $this->assertSame([], $periods);
    }

    #[Test]
    public function closed_plan_through_current_period_end_yields_nine_months_by_september(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'monthly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-09-30');

        $this->assertCount(9, $periods);
        $this->assertSame('2026-01', $periods[0]['period_label']);
        $this->assertSame('2026-09', $periods[8]['period_label']);
    }

    #[Test]
    public function quarterly_through_current_period_end_yields_three_quarters_by_september(): void
    {
        $plan = new ContributionPlan([
            'tenant_id' => 1,
            'frequency' => 'quarterly',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $periods = ContributionPeriod::enumerateForPlan($plan, '2026-01-01', '2026-09-30');

        $this->assertCount(3, $periods);
        $this->assertSame('2026-Q3', $periods[2]['period_label']);
    }
}
