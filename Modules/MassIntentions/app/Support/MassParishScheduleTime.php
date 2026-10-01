<?php

namespace Modules\MassIntentions\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Donations\Support\DonationBusinessDate;

final class MassParishScheduleTime
{
    /**
     * Reject clock times that do not exist on the next calendar occurrence of the weekday in the parish TZ.
     */
    public static function assertValidForWeekday(int $tenantId, int $weekday, string $timeHhMm): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $timeHhMm)) {
            throw ValidationException::withMessages([
                'slots' => 'Each Mass time must use HH:MM format.',
            ]);
        }

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $today = Carbon::parse(DonationBusinessDate::today($tenantId), $timezone)->startOfDay();
        $cursor = $today->copy();
        for ($i = 0; $i < 8; $i++) {
            if ((int) $cursor->format('w') === $weekday) {
                break;
            }
            $cursor->addDay();
        }

        $candidate = Carbon::createFromFormat(
            'Y-m-d H:i',
            $cursor->format('Y-m-d').' '.$timeHhMm,
            $timezone
        );

        if ($candidate === false) {
            throw ValidationException::withMessages([
                'slots' => 'That Mass time is not valid in your parish timezone.',
            ]);
        }

        if ($candidate->format('H:i') !== $timeHhMm) {
            throw ValidationException::withMessages([
                'slots' => 'That Mass time is not valid in your parish timezone.',
            ]);
        }
    }
}
