<?php

namespace Modules\Donations\Tests\Unit;

use Carbon\Carbon;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Support\ContributionBalance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContributionBalanceTest extends TestCase
{
    #[Test]
    public function september_is_current_on_mid_month_without_grace(): void
    {
        $due = $this->makeDue('2026-09-01', '2026-09-30', '2026-09-30', 100, 0);

        $this->assertSame('current', ContributionBalance::scheduleState($due, '2026-09-19'));
        $this->assertTrue(ContributionBalance::isCollectable($due, '2026-09-19'));
    }

    #[Test]
    public function january_is_collectable_in_grace_window_before_due_date(): void
    {
        $due = $this->makeDue('2026-01-01', '2026-01-31', '2026-02-05', 100, 0);

        $this->assertSame('grace', ContributionBalance::scheduleState($due, '2026-02-03'));
        $this->assertTrue(ContributionBalance::isCollectable($due, '2026-02-03'));
    }

    #[Test]
    public function future_period_is_not_collectable(): void
    {
        $due = $this->makeDue('2026-10-01', '2026-10-31', '2026-11-05', 100, 0);

        $this->assertSame('future', ContributionBalance::scheduleState($due, '2026-09-19'));
        $this->assertFalse(ContributionBalance::isCollectable($due, '2026-09-19'));
    }

    #[Test]
    public function overdue_when_due_date_passed(): void
    {
        $due = $this->makeDue('2026-08-01', '2026-08-31', '2026-08-31', 100, 0);

        $this->assertSame('overdue', ContributionBalance::scheduleState($due, '2026-09-19'));
    }

    #[Test]
    public function legacy_due_without_period_bounds_uses_due_date(): void
    {
        $due = $this->makeDue(null, null, '2026-08-31', 100, 0);

        $this->assertTrue(ContributionBalance::isCollectable($due, '2026-09-19'));
        $this->assertSame('overdue', ContributionBalance::scheduleState($due, '2026-09-19'));
    }

    private function makeDue(
        ?string $periodStart,
        ?string $periodEnd,
        string $dueDate,
        float $amountDue,
        float $amountPaid
    ): ContributionDue {
        $due = new ContributionDue([
            'status' => 'pending',
            'amount_due' => $amountDue,
            'amount_paid' => $amountPaid,
        ]);

        if ($periodStart !== null) {
            $due->period_start = Carbon::parse($periodStart);
        }
        if ($periodEnd !== null) {
            $due->period_end = Carbon::parse($periodEnd);
        }
        $due->due_date = Carbon::parse($dueDate);

        return $due;
    }
}
