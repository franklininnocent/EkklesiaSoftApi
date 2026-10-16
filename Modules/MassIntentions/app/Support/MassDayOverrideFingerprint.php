<?php

namespace Modules\MassIntentions\Support;

use Modules\MassIntentions\Models\MassDayOverride;
use Modules\MassIntentions\Models\MassDayOverrideSlot;

final class MassDayOverrideFingerprint
{
    public static function forOverride(MassDayOverride $override): string
    {
        $override->loadMissing('slots');

        $slots = $override->slots
            ->sortBy('sort_order')
            ->map(fn (MassDayOverrideSlot $slot) => [
                'slot_id' => $slot->slot_id,
                'celebrated_at' => substr((string) $slot->celebrated_at, 0, 8),
                'place' => $slot->place,
                'celebrant_name' => $slot->celebrant_name,
                'place_key' => $slot->place_key,
            ])
            ->values()
            ->all();

        $payload = [
            'id' => $override->id,
            'override_on' => $override->override_on?->format('Y-m-d'),
            'mode' => $override->mode,
            'closes_regular_masses' => (bool) $override->closes_regular_masses,
            'label' => $override->label,
            'slots' => $slots,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
