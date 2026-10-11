<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Models\MassScheduleRevision;
use Modules\MassIntentions\Models\MassScheduleSlot;
use Modules\MassIntentions\Support\MassParishScheduleTime;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\MassIntentions\Support\MassSchedulePlaceKey;
use Modules\MassIntentions\Support\MassScheduleWeekOfMonth;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MassScheduleService
{
    public function __construct(
        private readonly MassOccurrenceReconciler $reconciler,
        private readonly MassScheduleExpectedBuilder $expectedBuilder,
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    public function getOrCreateRegular(int $tenantId, User $actor): MassSchedule
    {
        $existing = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'regular')
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return MassSchedule::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Regular Mass schedule',
            'kind' => 'regular',
            'coverage_mode' => 'full_week',
            'status' => 'active',
            'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function regularBundle(int $tenantId, User $actor): array
    {
        $schedule = $this->getOrCreateRegular($tenantId, $actor);
        $draft = $this->draftRevision($tenantId, $schedule);
        $published = $this->latestPublishedRevision($tenantId, $schedule);

        return [
            'schedule' => $this->scheduleToArray($schedule),
            'draft' => $draft ? $this->revisionToArray($draft) : null,
            'published' => $published ? $this->revisionToArray($published) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemporaries(int $tenantId, bool $includeInactive = false): array
    {
        $query = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'temporary')
            ->orderByDesc('created_at');

        if ($includeInactive) {
            $query->whereIn('status', ['active', 'inactive']);
        } else {
            $query->where('status', 'active');
        }

        return $query->get()
            ->map(function (MassSchedule $schedule) use ($tenantId) {
                $published = $this->latestPublishedRevision($tenantId, $schedule);

                return [
                    'schedule' => $this->scheduleToArray($schedule),
                    'published' => $published ? $this->revisionToArray($published) : null,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTemporary(int $tenantId, User $actor, array $payload): array
    {
        $coverage = (string) ($payload['coverage_mode'] ?? 'full_week');
        $selected = $payload['selected_weekdays'] ?? null;
        if ($coverage === 'selected_weekdays') {
            if (! is_array($selected) || $selected === []) {
                throw ValidationException::withMessages([
                    'selected_weekdays' => 'Choose at least one weekday for this temporary schedule.',
                ]);
            }
        } else {
            $selected = null;
        }

        return DB::transaction(function () use ($tenantId, $actor, $payload, $coverage, $selected): array {
            $schedule = MassSchedule::query()->create([
                'tenant_id' => $tenantId,
                'name' => (string) $payload['name'],
                'kind' => 'temporary',
                'coverage_mode' => $coverage,
                'selected_weekdays' => $selected,
                'status' => 'active',
                'created_by_user_id' => $actor->id,
            ]);

            MassScheduleRevision::query()->create([
                'tenant_id' => $tenantId,
                'schedule_id' => $schedule->id,
                'revision_number' => 1,
                'status' => 'draft',
            ]);

            $this->audits->record($tenantId, 'schedule.draft_saved', $actor, null, null, [
                'schedule_id' => $schedule->id,
                'kind' => 'temporary',
            ]);

            return $this->scheduleBundle($tenantId, $schedule->id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleBundle(int $tenantId, string $scheduleId): array
    {
        $schedule = $this->findSchedule($tenantId, $scheduleId);
        $draft = $this->draftRevision($tenantId, $schedule);
        $published = $this->latestPublishedRevision($tenantId, $schedule);

        return [
            'schedule' => $this->scheduleToArray($schedule),
            'draft' => $draft ? $this->revisionToArray($draft) : null,
            'published' => $published ? $this->revisionToArray($published) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function saveDraft(int $tenantId, User $actor, string $scheduleId, array $payload): array
    {
        $schedule = $this->findSchedule($tenantId, $scheduleId);

        return DB::transaction(function () use ($tenantId, $actor, $schedule, $payload): array {
            MassSchedule::query()->where('id', $schedule->id)->lockForUpdate()->first();

            if (array_key_exists('default_place', $payload)) {
                $schedule->default_place = $payload['default_place'];
            }
            if (array_key_exists('default_celebrant_name', $payload)) {
                $schedule->default_celebrant_name = $payload['default_celebrant_name'];
            }
            if ($schedule->kind === 'temporary') {
                if (array_key_exists('name', $payload) && is_string($payload['name'])) {
                    $schedule->name = $payload['name'];
                }
                if (array_key_exists('coverage_mode', $payload)) {
                    $schedule->coverage_mode = (string) $payload['coverage_mode'];
                }
                if (array_key_exists('selected_weekdays', $payload)) {
                    $schedule->selected_weekdays = $payload['selected_weekdays'];
                }
            }
            $schedule->save();

            $revision = $this->draftRevision($tenantId, $schedule);
            if ($revision === null) {
                $revision = $this->createDraftFromPublishedOrBlank($tenantId, $schedule);
            }

            $slots = $payload['slots'] ?? [];
            if (! is_array($slots)) {
                throw ValidationException::withMessages(['slots' => 'Slots must be an array.']);
            }

            if (count($slots) > MassScheduleConstants::MAX_SLOTS_PER_REVISION) {
                throw ValidationException::withMessages([
                    'slots' => 'Too many Mass times in this schedule.',
                ]);
            }

            $this->assertDraftSlotsValid($tenantId, $schedule, $slots);

            $knownSlotIds = $this->knownSlotIdsForSchedule($tenantId, $schedule->id);
            MassScheduleSlot::query()
                ->where('tenant_id', $tenantId)
                ->where('revision_id', $revision->id)
                ->delete();

            $sort = 0;
            foreach ($slots as $slotInput) {
                if (! is_array($slotInput)) {
                    continue;
                }
                $weekday = (int) ($slotInput['weekday'] ?? -1);
                if ($weekday < 0 || $weekday > 6) {
                    throw ValidationException::withMessages(['slots' => 'Each slot needs a valid weekday.']);
                }

                $time = $this->normalizeTime((string) ($slotInput['celebrated_at'] ?? ''));
                if ($time === null) {
                    throw ValidationException::withMessages(['slots' => 'Each slot needs a time.']);
                }

                $placeSource = (string) ($slotInput['place_source'] ?? 'inherit');
                $celebrantSource = (string) ($slotInput['celebrant_source'] ?? 'inherit');
                $place = $slotInput['place'] ?? null;
                $celebrant = $slotInput['celebrant_name'] ?? null;

                $slotId = (string) ($slotInput['slot_id'] ?? '');
                if ($slotId === '' || ! in_array($slotId, $knownSlotIds, true)) {
                    $slotId = (string) Str::uuid();
                }

                $resolvedPlace = $placeSource === 'override' ? $place : ($placeSource === 'unset' ? null : $schedule->default_place);
                $placeKey = MassSchedulePlaceKey::fromPlace($resolvedPlace);

                $weeksOfMonth = MassScheduleWeekOfMonth::normalize(
                    isset($slotInput['weeks_of_month']) && is_array($slotInput['weeks_of_month'])
                        ? $slotInput['weeks_of_month']
                        : null
                );

                MassScheduleSlot::query()->create([
                    'tenant_id' => $tenantId,
                    'revision_id' => $revision->id,
                    'slot_id' => $slotId,
                    'weekday' => $weekday,
                    'weeks_of_month' => $weeksOfMonth,
                    'celebrated_at' => $time,
                    'place' => $placeSource === 'override' ? $place : null,
                    'celebrant_name' => $celebrantSource === 'override' ? $celebrant : null,
                    'place_source' => $placeSource,
                    'celebrant_source' => $celebrantSource,
                    'place_key' => $placeKey,
                    'sort_order' => $sort++,
                ]);
            }

            $revision->content_fingerprint = null;
            $revision->save();

            $this->audits->record($tenantId, 'schedule.draft_saved', $actor, null, null, [
                'schedule_id' => $schedule->id,
                'revision_id' => $revision->id,
                'slot_count' => count($slots),
            ]);

            return $this->revisionToArray($revision->fresh(['slots']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(
        int $tenantId,
        string $scheduleId,
        string $applyFrom,
        ?string $until = null,
        ?string $effectiveTo = null
    ): array {
        $schedule = $this->findSchedule($tenantId, $scheduleId);
        $draft = $this->draftRevision($tenantId, $schedule);
        if ($draft === null) {
            throw ValidationException::withMessages(['draft' => 'Save a draft before previewing.']);
        }

        if ($schedule->kind === 'temporary') {
            $this->validateTemporaryWindow($applyFrom, $effectiveTo);
        }

        $rangeUntil = $schedule->kind === 'temporary' ? $effectiveTo : $until;
        $this->assertApplyRangeWithinCap($applyFrom, $rangeUntil, $schedule->kind === 'temporary' ? 'effective_to' : 'until');
        [$from, $to] = $this->reconcileRange($tenantId, $applyFrom, $rangeUntil);
        $fingerprint = MassScheduleFingerprint::forRevision($draft, $schedule, $applyFrom, $effectiveTo);

        if ($schedule->kind === 'temporary') {
            $expected = $this->expectedBuilder->buildForTemporaryDraftPreview(
                $tenantId,
                $schedule,
                $draft,
                $from,
                $to,
                $applyFrom,
                (string) $effectiveTo
            );
            $reconcile = $this->reconciler->previewWithExpected($tenantId, $from, $to, $expected);
        } else {
            $expected = $this->expectedBuilder->buildForRegularDraftPreview(
                $tenantId,
                $schedule,
                $draft,
                $from,
                $to,
                $applyFrom
            );
            $reconcile = $this->reconciler->previewWithExpected($tenantId, $from, $to, $expected);
        }
        ['counts' => $counts, 'conflicts' => $conflicts] = $this->splitReconcileResult($reconcile);

        $schedule->last_preview_conflict_count = (int) ($counts['conflict'] ?? 0);
        $schedule->last_preview_at = now();
        $schedule->save();

        return [
            'fingerprint' => $fingerprint,
            'apply_from' => $applyFrom,
            'range_from' => $from->toDateString(),
            'range_to' => $to->toDateString(),
            'counts' => $counts,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(
        int $tenantId,
        User $actor,
        string $scheduleId,
        string $applyFrom,
        string $fingerprint,
        ?string $until = null,
        ?string $changeReason = null,
        ?string $effectiveTo = null
    ): array {
        $schedule = $this->findSchedule($tenantId, $scheduleId);

        return DB::transaction(function () use (
            $tenantId,
            $actor,
            $schedule,
            $applyFrom,
            $fingerprint,
            $until,
            $changeReason,
            $effectiveTo
        ): array {
            MassSchedule::query()->where('id', $schedule->id)->lockForUpdate()->first();

            $draft = $this->draftRevision($tenantId, $schedule);
            if ($draft === null) {
                throw ValidationException::withMessages(['draft' => 'Save a draft before applying.']);
            }

            if ($schedule->kind === 'temporary') {
                $this->validateTemporaryWindow($applyFrom, $effectiveTo);
                $this->assertNoTemporaryOverlap($tenantId, $schedule->id, $applyFrom, (string) $effectiveTo);
            }

            $rangeUntil = $schedule->kind === 'temporary' ? $effectiveTo : $until;
            $this->assertApplyRangeWithinCap($applyFrom, $rangeUntil, $schedule->kind === 'temporary' ? 'effective_to' : 'until');

            $expectedFingerprint = MassScheduleFingerprint::forRevision($draft, $schedule, $applyFrom, $effectiveTo);
            if (! hash_equals($expectedFingerprint, $fingerprint)) {
                throw new HttpException(409, 'The schedule changed. Preview again.');
            }

            $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();
            $applyDate = Carbon::parse($applyFrom)->startOfDay();
            if ($applyDate->lt($today)) {
                throw ValidationException::withMessages([
                    'apply_from' => 'Apply date cannot be before today.',
                ]);
            }

            $previousPublished = $this->latestPublishedRevision($tenantId, $schedule);
            if ($previousPublished !== null) {
                $end = $applyDate->copy()->subDay();
                if ($end->gte($previousPublished->effective_from)) {
                    $previousPublished->effective_to = $end->toDateString();
                }
                $previousPublished->status = 'superseded';
                $previousPublished->save();
            }

            $draft->status = 'published';
            $draft->effective_from = $applyFrom;
            $draft->effective_to = $schedule->kind === 'temporary' ? $effectiveTo : null;
            $draft->content_fingerprint = $expectedFingerprint;
            $draft->published_at = now();
            $draft->published_by_user_id = $actor->id;
            $draft->change_reason = $changeReason;
            $draft->save();

            $rangeUntil = $schedule->kind === 'temporary' ? $effectiveTo : $until;
            [$from, $to] = $this->reconcileRange($tenantId, $applyFrom, $rangeUntil);
            $reconcile = $this->reconciler->apply($tenantId, $from, $to, $actor, $expectedFingerprint);
            ['counts' => $counts, 'conflicts' => $conflicts] = $this->splitReconcileResult($reconcile);

            MassGenerationCursor::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'last_generated_through' => $to->toDateString(),
                    'last_success_at' => now(),
                    'last_error' => null,
                    'last_fingerprint' => $expectedFingerprint,
                ]
            );

            $this->audits->record($tenantId, 'schedule.applied', $actor, null, null, [
                'schedule_id' => $schedule->id,
                'revision_id' => $draft->id,
                'apply_from' => $applyFrom,
                'fingerprint' => $expectedFingerprint,
                'counts' => $counts,
            ]);

            return [
                'revision' => $this->revisionToArray($draft->fresh(['slots'])),
                'counts' => $counts,
                'conflicts' => $conflicts,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $reconcile
     * @return array{counts: array<string, int>, conflicts: list<array<string, mixed>>}
     */
    private function splitReconcileResult(array $reconcile): array
    {
        $conflicts = $reconcile['conflicts'] ?? [];
        unset($reconcile['conflicts']);

        return [
            'counts' => array_map('intval', $reconcile),
            'conflicts' => is_array($conflicts) ? $conflicts : [],
        ];
    }

    private function assertApplyRangeWithinCap(string $applyFrom, ?string $until, string $field): void
    {
        if ($until === null || $until === '') {
            return;
        }

        $from = Carbon::parse($applyFrom)->startOfDay();
        $to = Carbon::parse($until)->startOfDay();
        $maxTo = $from->copy()->addDays(MassScheduleConstants::MAX_APPLY_RANGE_DAYS);
        if ($to->gt($maxTo)) {
            throw ValidationException::withMessages([
                $field => 'The date range cannot be more than '.MassScheduleConstants::MAX_APPLY_RANGE_DAYS.' days.',
            ]);
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function reconcileRange(int $tenantId, string $applyFrom, ?string $until): array
    {
        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();
        $from = Carbon::parse($applyFrom)->startOfDay();
        if ($from->lt($today)) {
            $from = $today->copy();
        }

        $horizon = $today->copy()->addDays(MassScheduleConstants::GENERATION_HORIZON_DAYS);
        if ($until !== null && $until !== '') {
            $to = Carbon::parse($until)->startOfDay();
            $maxTo = $from->copy()->addDays(MassScheduleConstants::MAX_APPLY_RANGE_DAYS);
            if ($to->gt($maxTo)) {
                $to = $maxTo;
            }
        } else {
            $to = $horizon;
        }

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        return [$from, $to];
    }

    private function findSchedule(int $tenantId, string $scheduleId): MassSchedule
    {
        return MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $scheduleId)
            ->firstOrFail();
    }

    private function draftRevision(int $tenantId, MassSchedule $schedule): ?MassScheduleRevision
    {
        return MassScheduleRevision::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->where('status', 'draft')
            ->orderByDesc('revision_number')
            ->first();
    }

    private function latestPublishedRevision(int $tenantId, MassSchedule $schedule): ?MassScheduleRevision
    {
        return MassScheduleRevision::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->where('status', 'published')
            ->orderByDesc('revision_number')
            ->first();
    }

    private function createDraftFromPublishedOrBlank(int $tenantId, MassSchedule $schedule): MassScheduleRevision
    {
        $published = $this->latestPublishedRevision($tenantId, $schedule);
        $nextNumber = (int) MassScheduleRevision::query()
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->max('revision_number') + 1;

        $draft = MassScheduleRevision::query()->create([
            'tenant_id' => $tenantId,
            'schedule_id' => $schedule->id,
            'revision_number' => $nextNumber,
            'status' => 'draft',
        ]);

        if ($published !== null) {
            foreach ($published->slots as $slot) {
                MassScheduleSlot::query()->create([
                    'tenant_id' => $tenantId,
                    'revision_id' => $draft->id,
                    'slot_id' => $slot->slot_id,
                    'weekday' => $slot->weekday,
                    'weeks_of_month' => $slot->weeks_of_month,
                    'celebrated_at' => $slot->celebrated_at,
                    'place' => $slot->place,
                    'celebrant_name' => $slot->celebrant_name,
                    'place_source' => $slot->place_source,
                    'celebrant_source' => $slot->celebrant_source,
                    'place_key' => $slot->place_key,
                    'sort_order' => $slot->sort_order,
                ]);
            }
        }

        return $draft->fresh(['slots']);
    }

    /**
     * @return list<string>
     */
    private function knownSlotIdsForSchedule(int $tenantId, string $scheduleId): array
    {
        return MassScheduleSlot::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('revision_id', function ($q) use ($tenantId, $scheduleId): void {
                $q->select('id')
                    ->from('mass_schedule_revisions')
                    ->where('tenant_id', $tenantId)
                    ->where('schedule_id', $scheduleId);
            })
            ->pluck('slot_id')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTime(string $time): ?string
    {
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemporaryWindow(string $applyFrom, ?string $effectiveTo): void
    {
        if ($effectiveTo === null || $effectiveTo === '') {
            throw ValidationException::withMessages([
                'effective_to' => 'Temporary schedules need an end date.',
            ]);
        }
        if ($effectiveTo < $applyFrom) {
            throw ValidationException::withMessages([
                'effective_to' => 'End date must be on or after the start date.',
            ]);
        }
    }

    private function assertNoTemporaryOverlap(
        int $tenantId,
        string $scheduleId,
        string $from,
        string $to
    ): void {
        $overlap = MassScheduleRevision::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'published')
            ->whereNotNull('effective_from')
            ->whereNotNull('effective_to')
            ->where('effective_from', '<=', $to)
            ->where('effective_to', '>=', $from)
            ->whereHas('schedule', function ($q) use ($tenantId, $scheduleId): void {
                $q->where('tenant_id', $tenantId)
                    ->where('kind', 'temporary')
                    ->where('status', 'active')
                    ->where('id', '!=', $scheduleId);
            })
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'effective_to' => 'Another temporary schedule already covers part of these dates. Change the dates or inactivate the other schedule first.',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRevisions(int $tenantId, string $scheduleId): array
    {
        $schedule = $this->findSchedule($tenantId, $scheduleId);

        return MassScheduleRevision::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->orderByDesc('revision_number')
            ->limit(50)
            ->get()
            ->map(fn (MassScheduleRevision $revision) => $this->revisionToArray($revision))
            ->all();
    }

    public function inactivate(int $tenantId, User $actor, string $scheduleId): array
    {
        $schedule = $this->findSchedule($tenantId, $scheduleId);

        if ($schedule->kind === 'regular') {
            throw ValidationException::withMessages([
                'schedule' => 'The regular weekly schedule cannot be inactivated. Publish a new revision instead.',
            ]);
        }

        if ($schedule->status !== 'active') {
            throw ValidationException::withMessages([
                'schedule' => 'This schedule is not active.',
            ]);
        }

        $schedule->status = 'inactive';
        $schedule->save();

        $this->audits->record($tenantId, 'schedule.inactivated', $actor, null, null, [
            'schedule_id' => $schedule->id,
        ]);

        return $this->scheduleToArray($schedule);
    }

    public function archive(int $tenantId, User $actor, string $scheduleId): array
    {
        $schedule = $this->findSchedule($tenantId, $scheduleId);

        if ($schedule->status !== 'inactive') {
            throw ValidationException::withMessages([
                'schedule' => 'Inactivate this schedule before archiving it.',
            ]);
        }

        $referenced = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('schedule_id', $schedule->id)
            ->exists();

        if ($referenced) {
            throw ValidationException::withMessages([
                'schedule' => 'This schedule still has Mass records. It cannot be archived.',
            ]);
        }

        $schedule->status = 'archived';
        $schedule->save();

        $this->audits->record($tenantId, 'schedule.archived', $actor, null, null, [
            'schedule_id' => $schedule->id,
        ]);

        return $this->scheduleToArray($schedule);
    }

    /**
     * @param  list<mixed>  $slots
     */
    private function assertDraftSlotsValid(int $tenantId, MassSchedule $schedule, array $slots): void
    {
        $perWeekday = [];
        $identityKeys = [];

        foreach ($slots as $slotInput) {
            if (! is_array($slotInput)) {
                continue;
            }

            $weekday = (int) ($slotInput['weekday'] ?? -1);
            if ($weekday < 0 || $weekday > 6) {
                continue;
            }

            $time = $this->normalizeTime((string) ($slotInput['celebrated_at'] ?? ''));
            if ($time === null) {
                continue;
            }

            MassParishScheduleTime::assertValidForWeekday($tenantId, $weekday, substr($time, 0, 5));

            $perWeekday[$weekday] = ($perWeekday[$weekday] ?? 0) + 1;
            if ($perWeekday[$weekday] > MassScheduleConstants::MAX_SLOTS_PER_WEEKDAY) {
                throw ValidationException::withMessages([
                    'slots' => 'You can add at most '.MassScheduleConstants::MAX_SLOTS_PER_WEEKDAY.' Mass times per day.',
                ]);
            }

            $placeSource = (string) ($slotInput['place_source'] ?? 'inherit');
            $place = $slotInput['place'] ?? null;
            $resolvedPlace = $placeSource === 'override' ? $place : ($placeSource === 'unset' ? null : $schedule->default_place);
            $placeKey = MassSchedulePlaceKey::fromPlace($resolvedPlace);
            $identity = $weekday.'|'.$time.'|'.$placeKey;
            if (isset($identityKeys[$identity])) {
                throw ValidationException::withMessages([
                    'slots' => 'Two Mass times on the same day cannot share the same time and place.',
                ]);
            }
            $identityKeys[$identity] = true;
        }
    }

    private function scheduleToArray(MassSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'kind' => $schedule->kind,
            'coverage_mode' => $schedule->coverage_mode,
            'selected_weekdays' => $schedule->selected_weekdays,
            'status' => $schedule->status,
            'default_place' => $schedule->default_place,
            'default_celebrant_name' => $schedule->default_celebrant_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionToArray(MassScheduleRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'revision_number' => $revision->revision_number,
            'status' => $revision->status,
            'effective_from' => $revision->effective_from?->format('Y-m-d'),
            'effective_to' => $revision->effective_to?->format('Y-m-d'),
            'slots' => $revision->slots->map(fn (MassScheduleSlot $slot) => [
                'slot_id' => $slot->slot_id,
                'weekday' => (int) $slot->weekday,
                'weeks_of_month' => $slot->weeks_of_month,
                'celebrated_at' => substr((string) $slot->celebrated_at, 0, 5),
                'place' => $slot->place,
                'celebrant_name' => $slot->celebrant_name,
                'place_source' => $slot->place_source,
                'celebrant_source' => $slot->celebrant_source,
            ])->values()->all(),
        ];
    }
}
