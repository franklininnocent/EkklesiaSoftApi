<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Collection;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Models\MassScheduleRevision;
use Modules\MassIntentions\Models\MassScheduleSlot;

final class MassScheduleFingerprint
{
    public static function forRevision(
        MassScheduleRevision $revision,
        MassSchedule $schedule,
        string $applyFrom,
        ?string $effectiveTo = null
    ): string {
        $slots = $revision->slots()
            ->orderBy('weekday')
            ->orderBy('celebrated_at')
            ->orderBy('place_key')
            ->get()
            ->map(fn (MassScheduleSlot $slot) => [
                'slot_id' => $slot->slot_id,
                'weekday' => $slot->weekday,
                'weeks_of_month' => $slot->weeks_of_month,
                'celebrated_at' => self::timeKey($slot->celebrated_at),
                'place_key' => $slot->place_key,
                'place_source' => $slot->place_source,
                'celebrant_source' => $slot->celebrant_source,
                'place' => $slot->place,
                'celebrant_name' => $slot->celebrant_name,
            ])
            ->values()
            ->all();

        $payload = [
            'schedule_id' => $schedule->id,
            'kind' => $schedule->kind,
            'revision_id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'apply_from' => $applyFrom,
            'effective_to' => $schedule->kind === 'temporary' ? $effectiveTo : null,
            'coverage_mode' => $schedule->coverage_mode,
            'selected_weekdays' => $schedule->selected_weekdays,
            'default_place' => $schedule->default_place,
            'default_celebrant_name' => $schedule->default_celebrant_name,
            'slots' => $slots,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function timeKey(mixed $time): string
    {
        if ($time === null) {
            return '';
        }

        return substr((string) $time, 0, 5);
    }
}
