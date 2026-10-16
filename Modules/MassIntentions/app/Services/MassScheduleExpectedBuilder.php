<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Modules\MassIntentions\Models\MassDayOverride;
use Modules\MassIntentions\Models\MassDayOverrideSlot;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Models\MassScheduleRevision;
use Modules\MassIntentions\Models\MassScheduleSlot;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassSchedulePlaceKey;
use Modules\MassIntentions\Support\MassScheduleWeekOfMonth;

/**
 * @phpstan-type ExpectedOccurrence array{
 *   date: string,
 *   slot_id: string,
 *   celebrated_at: string,
 *   place: ?string,
 *   celebrant_name: ?string,
 *   origin: string,
 *   schedule_id: string,
 *   revision_id: ?string,
 *   day_override_id: ?string,
 *   source_label: string,
 * }
 */
final class MassScheduleExpectedBuilder
{
    /**
     * @return list<ExpectedOccurrence>
     */
    public function buildForRange(int $tenantId, Carbon $from, Carbon $to): array
    {
        $expected = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $expected = array_merge($expected, $this->expectedForTenantDatePublished($tenantId, $cursor));
            $cursor->addDay();
        }

        return $expected;
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    public function buildForRevisionInRange(
        MassSchedule $schedule,
        MassScheduleRevision $revision,
        Carbon $from,
        Carbon $to
    ): array {
        $revision->loadMissing('slots');
        $origin = $schedule->kind === 'temporary'
            ? MassCelebrationOrigin::TEMPORARY
            : MassCelebrationOrigin::REGULAR;
        $expected = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $expected = array_merge($expected, $this->expectedForDate($cursor, $revision, $schedule, $origin));
            $cursor->addDay();
        }

        return $expected;
    }

    /**
     * Preview a not-yet-published temporary revision against regular + other published temporaries.
     *
     * @return list<ExpectedOccurrence>
     */
    public function buildForTemporaryDraftPreview(
        int $tenantId,
        MassSchedule $schedule,
        MassScheduleRevision $draft,
        Carbon $from,
        Carbon $to,
        string $applyFrom,
        string $effectiveTo
    ): array {
        $draft->loadMissing('slots');
        $windowStart = Carbon::parse($applyFrom)->startOfDay();
        $windowEnd = Carbon::parse($effectiveTo)->startOfDay();

        $expected = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if ($cursor->gte($windowStart) && $cursor->lte($windowEnd) && $this->temporaryAppliesOnDate($schedule, $cursor)) {
                $expected = array_merge(
                    $expected,
                    $this->expectedForDate($cursor, $draft, $schedule, MassCelebrationOrigin::TEMPORARY)
                );
            } else {
                $expected = array_merge($expected, $this->expectedForTenantDatePublished($tenantId, $cursor, $schedule->id));
            }
            $cursor->addDay();
        }

        return $expected;
    }

    /**
     * Preview a not-yet-published regular revision with published temporaries and day overrides.
     *
     * @return list<ExpectedOccurrence>
     */
    public function buildForRegularDraftPreview(
        int $tenantId,
        MassSchedule $schedule,
        MassScheduleRevision $draft,
        Carbon $from,
        Carbon $to,
        string $applyFrom
    ): array {
        $draft->loadMissing('slots');
        $applyDate = Carbon::parse($applyFrom)->startOfDay();

        $expected = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            if ($cursor->lt($applyDate)) {
                $expected = array_merge($expected, $this->expectedForTenantDatePublished($tenantId, $cursor));
            } else {
                $expected = array_merge(
                    $expected,
                    $this->expectedForTenantDatePublishedWithRegularDraft($tenantId, $cursor, $schedule, $draft)
                );
            }
            $cursor->addDay();
        }

        return $expected;
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    private function expectedForTenantDatePublished(int $tenantId, Carbon $date, ?string $ignoreTemporaryScheduleId = null): array
    {
        $weekday = (int) $date->format('w');
        $dateStr = $date->toDateString();

        $dayOverride = $this->activeDayOverrideForDate($tenantId, $dateStr);
        if ($dayOverride !== null) {
            $overrideItems = $this->expectedForDayOverride($date, $dayOverride);
            if ($dayOverride->mode === 'replace') {
                return $overrideItems;
            }

            $base = $this->expectedForTenantDateWithoutDayOverride($tenantId, $date, $ignoreTemporaryScheduleId);

            return $this->mergeSupplement($base, $overrideItems);
        }

        return $this->expectedForTenantDateWithoutDayOverride($tenantId, $date, $ignoreTemporaryScheduleId);
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    private function expectedForTenantDatePublishedWithRegularDraft(
        int $tenantId,
        Carbon $date,
        MassSchedule $regularSchedule,
        MassScheduleRevision $draftRegularRevision
    ): array {
        $dateStr = $date->toDateString();

        $dayOverride = $this->activeDayOverrideForDate($tenantId, $dateStr);
        if ($dayOverride !== null) {
            $overrideItems = $this->expectedForDayOverride($date, $dayOverride);
            if ($dayOverride->mode === 'replace') {
                return $overrideItems;
            }

            $base = $this->expectedForTenantDateWithoutDayOverride(
                $tenantId,
                $date,
                null,
                $draftRegularRevision,
                $regularSchedule
            );

            return $this->mergeSupplement($base, $overrideItems);
        }

        return $this->expectedForTenantDateWithoutDayOverride(
            $tenantId,
            $date,
            null,
            $draftRegularRevision,
            $regularSchedule
        );
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    private function expectedForTenantDateWithoutDayOverride(
        int $tenantId,
        Carbon $date,
        ?string $ignoreTemporaryScheduleId = null,
        ?MassScheduleRevision $regularRevisionOverride = null,
        ?MassSchedule $regularScheduleOverride = null,
    ): array {
        $weekday = (int) $date->format('w');
        $dateStr = $date->toDateString();

        if ($regularRevisionOverride !== null && $regularScheduleOverride !== null) {
            $regularRevision = $regularRevisionOverride;
            $regularSchedule = $regularScheduleOverride;
        } else {
            $regularRevision = $this->publishedRegularRevisionForDate($tenantId, $date);
            $regularSchedule = $regularRevision
                ? MassSchedule::query()->where('tenant_id', $tenantId)->where('id', $regularRevision->schedule_id)->first()
                : null;
        }
        $regularItems = ($regularRevision && $regularSchedule)
            ? $this->expectedForDate($date, $regularRevision, $regularSchedule, MassCelebrationOrigin::REGULAR)
            : [];

        $temporaryRevision = $this->publishedTemporaryRevisionForDate($tenantId, $dateStr, $ignoreTemporaryScheduleId);
        if ($temporaryRevision === null) {
            return $regularItems;
        }

        $temporarySchedule = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $temporaryRevision->schedule_id)
            ->first();

        if ($temporarySchedule === null) {
            return $regularItems;
        }

        if ($temporarySchedule->coverage_mode === 'full_week') {
            return $this->expectedForDate($date, $temporaryRevision, $temporarySchedule, MassCelebrationOrigin::TEMPORARY);
        }

        $selected = $temporarySchedule->selected_weekdays ?? [];
        if (in_array($weekday, $selected, true)) {
            return $this->expectedForDate($date, $temporaryRevision, $temporarySchedule, MassCelebrationOrigin::TEMPORARY);
        }

        return $regularItems;
    }

    private function activeDayOverrideForDate(int $tenantId, string $dateStr): ?MassDayOverride
    {
        return MassDayOverride::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereDate('override_on', $dateStr)
            ->first();
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    private function expectedForDayOverride(Carbon $date, MassDayOverride $override): array
    {
        if ($override->closes_regular_masses && $override->slots->isEmpty()) {
            return [];
        }

        $label = trim((string) $override->label) !== '' ? (string) $override->label : 'Special schedule';
        $items = [];
        foreach ($override->slots->sortBy('sort_order') as $slot) {
            $time = substr((string) $slot->celebrated_at, 0, 8);
            if (strlen($time) === 5) {
                $time .= ':00';
            }
            $items[] = [
                'date' => $date->toDateString(),
                'slot_id' => $slot->slot_id,
                'celebrated_at' => $time,
                'place' => $slot->place,
                'celebrant_name' => $slot->celebrant_name,
                'origin' => MassCelebrationOrigin::SPECIAL_DAY,
                'schedule_id' => null,
                'revision_id' => null,
                'day_override_id' => $override->id,
                'source_label' => $label,
            ];
        }

        return $items;
    }

    /**
     * @param  list<ExpectedOccurrence>  $base
     * @param  list<ExpectedOccurrence>  $supplement
     * @return list<ExpectedOccurrence>
     */
    private function mergeSupplement(array $base, array $supplement): array
    {
        $keys = [];
        foreach ($base as $item) {
            $keys[$this->slotIdentityKey($item['celebrated_at'], $item['place'])] = true;
        }
        $merged = $base;
        foreach ($supplement as $item) {
            $key = $this->slotIdentityKey($item['celebrated_at'], $item['place']);
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $merged[] = $item;
        }

        return $merged;
    }

    private function slotIdentityKey(string $celebratedAt, ?string $place): string
    {
        $time = substr($celebratedAt, 0, 5);

        return $time.'|'.MassSchedulePlaceKey::fromPlace($place);
    }

    private function temporaryAppliesOnDate(MassSchedule $schedule, Carbon $date): bool
    {
        if ($schedule->coverage_mode === 'full_week') {
            return true;
        }

        $weekday = (int) $date->format('w');
        $selected = $schedule->selected_weekdays ?? [];

        return in_array($weekday, $selected, true);
    }

    /**
     * @return list<ExpectedOccurrence>
     */
    private function expectedForDate(
        Carbon $date,
        MassScheduleRevision $revision,
        MassSchedule $schedule,
        string $origin
    ): array {
        $weekday = (int) $date->format('w');
        $slots = $revision->slots->filter(fn (MassScheduleSlot $s) => (int) $s->weekday === $weekday);
        $items = [];
        foreach ($slots as $slot) {
            if (! MassScheduleWeekOfMonth::matchesDate($slot->weeks_of_month, $date)) {
                continue;
            }
            $items[] = $this->toExpected($date, $slot, $schedule, $revision, $origin);
        }

        return $items;
    }

    private function publishedRegularRevisionForDate(int $tenantId, Carbon $date): ?MassScheduleRevision
    {
        $dateStr = $date->toDateString();

        return MassScheduleRevision::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $dateStr);
            })
            ->whereHas('schedule', function ($q) use ($tenantId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('kind', 'regular')
                    ->where('status', 'active');
            })
            ->orderByDesc('revision_number')
            ->first();
    }

    private function publishedTemporaryRevisionForDate(
        int $tenantId,
        string $dateStr,
        ?string $ignoreScheduleId = null
    ): ?MassScheduleRevision {
        return MassScheduleRevision::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->where('effective_from', '<=', $dateStr)
            ->whereNotNull('effective_to')
            ->where('effective_to', '>=', $dateStr)
            ->whereHas('schedule', function ($q) use ($tenantId, $ignoreScheduleId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('kind', 'temporary')
                    ->where('status', 'active');
                if ($ignoreScheduleId !== null) {
                    $q->where('id', '!=', $ignoreScheduleId);
                }
            })
            ->orderByDesc('revision_number')
            ->first();
    }

    /**
     * @return ExpectedOccurrence
     */
    private function toExpected(
        Carbon $date,
        MassScheduleSlot $slot,
        MassSchedule $schedule,
        MassScheduleRevision $revision,
        string $origin
    ): array {
        $time = substr((string) $slot->celebrated_at, 0, 8);
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        return [
            'date' => $date->toDateString(),
            'slot_id' => $slot->slot_id,
            'celebrated_at' => $time,
            'place' => $this->resolvePlace($slot, $schedule),
            'celebrant_name' => $this->resolveCelebrant($slot, $schedule),
            'origin' => $origin,
            'schedule_id' => $schedule->id,
            'revision_id' => $revision->id,
            'day_override_id' => null,
            'source_label' => $this->sourceLabel((int) $slot->weekday, $time),
        ];
    }

    /**
     * Expected occurrences for one parish date (published schedules + active day override).
     *
     * @return list<ExpectedOccurrence>
     */
    public function buildForTenantDate(int $tenantId, Carbon $date): array
    {
        return $this->expectedForTenantDatePublished($tenantId, $date->copy()->startOfDay());
    }

    /**
     * Regular + temporary expected for a date, ignoring any day override.
     *
     * @return list<ExpectedOccurrence>
     */
    public function buildScheduleOnlyForTenantDate(int $tenantId, Carbon $date): array
    {
        return $this->expectedForTenantDateWithoutDayOverride($tenantId, $date->copy()->startOfDay());
    }

    private function resolvePlace(MassScheduleSlot $slot, MassSchedule $schedule): ?string
    {
        if ($slot->place_source === 'unset') {
            return null;
        }
        if ($slot->place_source === 'override') {
            return $slot->place;
        }

        return $schedule->default_place;
    }

    private function resolveCelebrant(MassScheduleSlot $slot, MassSchedule $schedule): ?string
    {
        if ($slot->celebrant_source === 'unset') {
            return null;
        }
        if ($slot->celebrant_source === 'override') {
            return $slot->celebrant_name;
        }

        return $schedule->default_celebrant_name;
    }

    private function sourceLabel(int $weekday, string $time): string
    {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $name = $days[$weekday] ?? 'Day';
        $displayTime = substr($time, 0, 5);

        return "{$name} {$displayTime}";
    }
}
