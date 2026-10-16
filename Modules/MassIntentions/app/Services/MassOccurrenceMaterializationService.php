<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MassOccurrenceMaterializationService
{
    public function __construct(
        private readonly MassOccurrenceReconciler $reconciler,
    ) {
    }

    /**
     * Materialize schedule-driven occurrences for a list/week view range (idempotent).
     */
    public function ensureForCelebrationList(int $tenantId, string $fromInput, string $toInput): void
    {
        if ($fromInput === '' || $toInput === '') {
            return;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput)) {
            return;
        }

        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();
        $from = Carbon::parse($fromInput)->startOfDay();
        $to = Carbon::parse($toInput)->startOfDay();

        $maxHorizon = $today->copy()->addDays(MassScheduleConstants::MAX_APPLY_RANGE_DAYS);
        if ($to->gt($maxHorizon)) {
            throw new HttpException(
                422,
                'Masses can only be loaded through '.MassScheduleConstants::MAX_APPLY_RANGE_DAYS.' days from today.'
            );
        }

        $hasRegular = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'regular')
            ->where('status', 'active')
            ->exists();

        if (! $hasRegular) {
            return;
        }

        $materializeFrom = $from->lt($today) ? $today->copy() : $from->copy();
        if ($to->lt($materializeFrom)) {
            return;
        }

        $runner = function () use ($tenantId, $materializeFrom, $to): void {
            $schedule = MassSchedule::query()
                ->where('tenant_id', $tenantId)
                ->where('kind', 'regular')
                ->where('status', 'active')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                return;
            }

            $this->reconciler->materialize($tenantId, $materializeFrom, $to);

            $cursor = MassGenerationCursor::query()->find($tenantId);
            $previousThrough = $cursor?->last_generated_through;
            $shouldAdvance = $previousThrough === null
                || Carbon::parse($previousThrough)->startOfDay()->lt($to);

            if ($shouldAdvance) {
                MassGenerationCursor::query()->updateOrCreate(
                    ['tenant_id' => $tenantId],
                    [
                        'last_generated_through' => $to->toDateString(),
                        'last_success_at' => now(),
                        'last_error' => null,
                    ]
                );
            }
        };

        if (DB::transactionLevel() > 0) {
            $runner();

            return;
        }

        DB::transaction($runner);
    }
}
