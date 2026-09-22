<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\ContributionPlan;
use Modules\Donations\Support\ContributionPeriod;

return new class extends Migration
{
    public function up(): void
    {
        ContributionDue::query()
            ->whereNull('period_start')
            ->orderBy('id')
            ->chunkById(200, function ($dues): void {
                foreach ($dues as $due) {
                    $plan = ContributionPlan::query()->find($due->plan_id);
                    if (!$plan) {
                        continue;
                    }

                    $reference = $due->due_date
                        ? Carbon::parse($due->due_date)
                        : Carbon::now();

                    try {
                        $period = ContributionPeriod::periodForFrequency($plan, $reference);
                    } catch (\InvalidArgumentException) {
                        continue;
                    }

                    if (($period['period_label'] ?? null) !== $due->period_label) {
                        $parsed = $this->parsePeriodLabel($due->period_label, $plan->frequency);
                        if ($parsed !== null) {
                            $period = array_merge($period, $parsed);
                        }
                    }

                    DB::table('contribution_dues')
                        ->where('id', $due->id)
                        ->update([
                            'period_start' => $period['period_start'],
                            'period_end' => $period['period_end'],
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive backfill; leave data in place on rollback.
    }

    /**
     * @return array{period_start: string, period_end: string}|null
     */
    private function parsePeriodLabel(string $label, string $frequency): ?array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $label, $m) && $frequency === 'monthly') {
            $start = Carbon::create((int) $m[1], (int) $m[2], 1);

            return [
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
            ];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $label, $m) && $frequency === 'quarterly') {
            $quarter = (int) $m[2];
            $start = Carbon::create((int) $m[1], ($quarter - 1) * 3 + 1, 1);

            return [
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->addMonths(2)->endOfMonth()->toDateString(),
            ];
        }

        if (preg_match('/^(\d{4})$/', $label, $m) && $frequency === 'yearly') {
            $start = Carbon::create((int) $m[1], 1, 1);

            return [
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfYear()->toDateString(),
            ];
        }

        return null;
    }
};
