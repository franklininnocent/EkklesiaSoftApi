<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassReconciliationRun;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Models\MassScheduleRevision;
use Modules\MassIntentions\Support\MassCelebrationOrigin;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassObligationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;

final class MassOccurrenceReconciler
{
    public function __construct(
        private readonly MassScheduleExpectedBuilder $expectedBuilder,
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    /**
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    public function preview(
        int $tenantId,
        Carbon $from,
        Carbon $to,
        ?MassSchedule $schedule = null,
        ?MassScheduleRevision $revision = null
    ): array {
        return $this->reconcile($tenantId, $from, $to, null, false, null, $schedule, $revision);
    }

    /**
     * @param  list<array<string, mixed>>  $expectedList
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    public function previewWithExpected(int $tenantId, Carbon $from, Carbon $to, array $expectedList): array
    {
        return $this->reconcile($tenantId, $from, $to, null, false, null, null, null, $expectedList);
    }

    /**
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    public function materialize(int $tenantId, Carbon $from, Carbon $to): array
    {
        return $this->reconcile($tenantId, $from, $to, null, true);
    }

    /**
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    public function apply(
        int $tenantId,
        Carbon $from,
        Carbon $to,
        ?User $actor,
        string $fingerprint
    ): array {
        return $this->reconcile($tenantId, $from, $to, $actor, true, $fingerprint, null, null);
    }

    /**
     * @param  list<array<string, mixed>>  $expectedList
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    public function applyWithExpected(
        int $tenantId,
        Carbon $from,
        Carbon $to,
        ?User $actor,
        string $fingerprint,
        array $expectedList
    ): array {
        return $this->reconcile($tenantId, $from, $to, $actor, true, $fingerprint, null, null, $expectedList);
    }

    /**
     * @return array{
     *   create: int,
     *   update: int,
     *   retain: int,
     *   suppress: int,
     *   skip: int,
     *   conflict: int,
     *   conflicts: list<array<string, mixed>>,
     * }
     */
    private function reconcile(
        int $tenantId,
        Carbon $from,
        Carbon $to,
        ?User $actor,
        bool $persist,
        ?string $fingerprint = null,
        ?MassSchedule $draftSchedule = null,
        ?MassScheduleRevision $draftRevision = null,
        ?array $expectedListOverride = null,
    ): array {
        $expectedList = $expectedListOverride ?? (
            $draftRevision !== null && $draftSchedule !== null
                ? $this->expectedBuilder->buildForRevisionInRange($draftSchedule, $draftRevision, $from, $to)
                : $this->expectedBuilder->buildForRange($tenantId, $from, $to)
        );
        $expectedByKey = [];
        foreach ($expectedList as $item) {
            $expectedByKey[$this->key($item['slot_id'], $item['date'])] = $item;
        }

        $existing = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('slot_id')
            ->whereDate('celebrated_on', '>=', $from->toDateString())
            ->whereDate('celebrated_on', '<=', $to->toDateString())
            ->get();

        $existingByKey = [];
        $existingByDateTime = [];
        foreach ($existing as $row) {
            $date = $row->celebrated_on->format('Y-m-d');
            $existingByKey[$this->key((string) $row->slot_id, $date)] = $row;
            $time = $row->celebrated_at ? substr((string) $row->celebrated_at, 0, 5) : '00:00';
            $existingByDateTime[$this->dateTimeKey($date, $time)] = $row;
        }

        $counts = [
            'create' => 0,
            'update' => 0,
            'retain' => 0,
            'suppress' => 0,
            'skip' => 0,
            'conflict' => 0,
        ];
        $conflicts = [];

        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $applyFrom = $from;

        $runner = function () use (
            $tenantId,
            $expectedByKey,
            $existingByKey,
            $existingByDateTime,
            $existing,
            $persist,
            $actor,
            $fingerprint,
            $timezone,
            $applyFrom,
            $from,
            $to,
            &$counts,
            &$conflicts
        ): array {
            $matchedCelebrationIds = [];

            foreach ($expectedByKey as $key => $expected) {
                $row = $existingByKey[$key] ?? null;
                if ($row === null) {
                    $time = substr((string) $expected['celebrated_at'], 0, 5);
                    $row = $existingByDateTime[$this->dateTimeKey($expected['date'], $time)] ?? null;
                }

                if ($row === null) {
                    if ($persist) {
                        $this->insertExpected($tenantId, $expected, $timezone, $actor);
                    }
                    $counts['create']++;

                    continue;
                }

                $matchedCelebrationIds[(string) $row->id] = true;

                if ($this->isSuppressedNonRecreatable($row)) {
                    $counts['skip']++;

                    continue;
                }

                if ($this->fieldsMatch($row, $expected)) {
                    $counts['retain']++;

                    continue;
                }

                if (MassCelebrationEligibility::canApplyMetadataOnlyReconciliation($row, $expected, $tenantId, $applyFrom)) {
                    if ($persist) {
                        $this->updateFromExpected($row, $expected);
                    }
                    $counts['update']++;

                    continue;
                }

                if (! MassCelebrationEligibility::isEligibleForReconciliation($row, $tenantId, $applyFrom)) {
                    if ($this->shouldSkipScheduleAttention($row, $tenantId)) {
                        $counts['skip']++;

                        continue;
                    }
                    $counts['conflict']++;
                    $saidCount = $this->saidFulfilmentCount($tenantId, $row->id);
                    $activeIntentions = MassCelebrationEligibility::intentionCount($tenantId, $row->id);
                    if ($saidCount > 0 && $activeIntentions > 0) {
                        $reasonCode = 'move_unsaid_only';
                    } elseif ($this->isApplyNewTimeConflict($row, $expected)) {
                        $reasonCode = 'apply_or_move';
                    } else {
                        $reasonCode = 'update_blocked';
                    }
                    $conflicts[] = $this->conflictPayload($row, $reasonCode, $expected);

                    continue;
                }

                if ($persist) {
                    $this->updateFromExpected($row, $expected);
                }
                $counts['update']++;
            }

            foreach ($existing as $row) {
                if (isset($matchedCelebrationIds[(string) $row->id])) {
                    continue;
                }

                $dateStr = $row->celebrated_on->format('Y-m-d');
                $key = $this->key((string) $row->slot_id, $dateStr);
                if (isset($expectedByKey[$key])) {
                    continue;
                }

                $time = $row->celebrated_at ? substr((string) $row->celebrated_at, 0, 5) : '00:00';
                $dtKey = $this->dateTimeKey($dateStr, $time);
                foreach ($expectedByKey as $expected) {
                    if ($this->dateTimeKey($expected['date'], substr((string) $expected['celebrated_at'], 0, 5)) === $dtKey) {
                        continue 2;
                    }
                }

                if ($row->origin === MassCelebrationOrigin::ONE_TIME) {
                    continue;
                }

                if ($this->isSuppressedNonRecreatable($row)) {
                    continue;
                }

                if ($row->generation_status !== MassGenerationStatus::ACTIVE) {
                    continue;
                }

                if (! MassCelebrationEligibility::isEligibleForReconciliation($row, $tenantId, $applyFrom)) {
                    if ($this->shouldSkipScheduleAttention($row, $tenantId)) {
                        $counts['skip']++;

                        continue;
                    }
                    $counts['conflict']++;
                    $replacement = $this->findScheduleReplacement($row, $expectedByKey, $existingByKey);
                    $reasonCode = $replacement !== null ? 'move_then_remove' : 'suppress_blocked';
                    $conflicts[] = $this->conflictPayload($row, $reasonCode, null, $replacement);

                    continue;
                }

                if ($persist) {
                    $saidCount = $this->saidFulfilmentCount($tenantId, $row->id);
                    $activeIntentions = MassCelebrationEligibility::intentionCount($tenantId, $row->id);

                    $row->generation_status = MassGenerationStatus::SUPPRESSED;
                    $row->suppression_reason = MassSuppressionReason::SCHEDULE_CHANGED;
                    $row->suppressed_at = now();
                    if ($row->source_label === null) {
                        $row->source_label = $this->fallbackSourceLabel($row);
                    }
                    $row->save();

                    if ($actor !== null) {
                        $this->audits->record($tenantId, 'celebration.schedule_changed', $actor, null, $row->id, [
                            'mass' => MassCelebrationEligibility::massSnapshot($row),
                            'intentions_said' => $saidCount,
                            'intentions_active' => $activeIntentions,
                        ]);
                    }
                }
                $counts['suppress']++;
            }

            if ($persist && $fingerprint !== null && $actor !== null) {
                MassReconciliationRun::query()->create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actor->id,
                    'fingerprint' => $fingerprint,
                    'range_from' => $from->toDateString(),
                    'range_to' => $to->toDateString(),
                    'counts' => $counts,
                    'conflict_count' => $counts['conflict'],
                ]);
            }

            return array_merge($counts, ['conflicts' => $conflicts]);
        };

        if (DB::transactionLevel() > 0) {
            return $runner();
        }

        return DB::transaction($runner);
    }

    private function key(string $slotId, string $date): string
    {
        return $slotId.'|'.$date;
    }

    private function dateTimeKey(string $date, string $time): string
    {
        return $date.'|'.substr($time, 0, 5);
    }

    private function shouldSkipScheduleAttention(MassCelebration $row, int $tenantId): bool
    {
        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();

        if ($row->celebrated_on === null || $row->celebrated_on->lt($today)) {
            return true;
        }

        if ($row->status === 'cancelled') {
            return true;
        }

        if ($row->generation_status === MassGenerationStatus::SUPPRESSED) {
            return true;
        }

        if ($row->is_exception) {
            return true;
        }

        if ($this->saidFulfilmentCount($tenantId, $row->id) > 0 && $this->activeUnsaidIntentionCount($tenantId, $row->id) === 0) {
            return true;
        }

        return false;
    }

    private function activeUnsaidIntentionCount(int $tenantId, string $celebrationId): int
    {
        return (int) DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->leftJoin('mass_intention_fulfilments as f', function ($join): void {
                $join->on('f.obligation_id', '=', 'o.id')->whereNull('f.undone_at');
            })
            ->where('a.tenant_id', $tenantId)
            ->where('a.celebration_id', $celebrationId)
            ->whereNull('a.unassigned_at')
            ->where('o.status', '!=', MassObligationStatus::SAID)
            ->whereNull('f.id')
            ->count();
    }

    private function isSuppressedNonRecreatable(MassCelebration $row): bool
    {
        if ($row->generation_status === MassGenerationStatus::SUPPRESSED) {
            return true;
        }

        if ($row->status === 'cancelled') {
            return true;
        }

        if ($row->suppression_reason === MassSuppressionReason::USER_CANCELLED) {
            return true;
        }

        return false;
    }

    private function fieldsMatch(MassCelebration $row, array $expected): bool
    {
        $rowTime = $row->celebrated_at ? substr((string) $row->celebrated_at, 0, 5) : null;
        $expTime = substr($expected['celebrated_at'], 0, 5);

        return $rowTime === $expTime
            && (string) $row->place === (string) ($expected['place'] ?? '')
            && (string) $row->celebrant_name === (string) ($expected['celebrant_name'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function insertExpected(int $tenantId, array $expected, string $timezone, ?User $actor): void
    {
        $connection = DB::connection();
        $useSavepoint = $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() > 0;

        if ($useSavepoint) {
            $connection->statement('SAVEPOINT mass_celebration_insert');
        }

        try {
            MassCelebration::query()->create([
                'tenant_id' => $tenantId,
                'origin' => $expected['origin'],
                'schedule_id' => $expected['schedule_id'],
                'revision_id' => $expected['revision_id'],
                'slot_id' => $expected['slot_id'],
                'day_override_id' => $expected['day_override_id'] ?? null,
                'celebrated_on' => $expected['date'],
                'celebrated_at' => substr($expected['celebrated_at'], 0, 5),
                'place' => $expected['place'],
                'celebrant_name' => $expected['celebrant_name'],
                'status' => 'scheduled',
                'generation_status' => MassGenerationStatus::ACTIVE,
                'source_label' => $expected['source_label'],
                'timezone' => $timezone,
                'created_by_user_id' => $actor?->id ?? $this->fallbackCreatorUserId($tenantId),
            ]);
        } catch (QueryException $e) {
            if ($useSavepoint) {
                $connection->statement('ROLLBACK TO SAVEPOINT mass_celebration_insert');
            }

            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function updateFromExpected(MassCelebration $row, array $expected): void
    {
        $row->slot_id = $expected['slot_id'];
        $row->celebrated_at = substr($expected['celebrated_at'], 0, 5);
        $row->place = $expected['place'];
        $row->celebrant_name = $expected['celebrant_name'];
        $row->revision_id = $expected['revision_id'];
        $row->schedule_id = $expected['schedule_id'];
        $row->day_override_id = $expected['day_override_id'] ?? null;
        $row->origin = $expected['origin'];
        $row->source_label = $expected['source_label'];
        $row->save();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '23505')
            || str_contains($message, 'mass_celebrations_tenant_slot_date_unique')
            || str_contains($message, 'UNIQUE constraint failed');
    }

    /**
     * @param  array<string, mixed>|null  $expected
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $expected
     */
    private function isApplyNewTimeConflict(MassCelebration $row, array $expected): bool
    {
        $rowDate = $row->celebrated_on?->format('Y-m-d');
        $expectedDate = isset($expected['celebrated_on']) ? (string) $expected['celebrated_on'] : null;

        return $rowDate !== null
            && $expectedDate !== null
            && $rowDate === $expectedDate
            && isset($expected['celebrated_at']);
    }

    private function saidFulfilmentCount(int $tenantId, string $celebrationId): int
    {
        return (int) DB::table('mass_intention_fulfilments')
            ->where('tenant_id', $tenantId)
            ->where('celebration_id', $celebrationId)
            ->whereNull('undone_at')
            ->count();
    }

    /**
     * @param  array<string, array<string, mixed>>  $expectedByKey
     * @param  array<string, MassCelebration>  $existingByKey
     * @return array<string, mixed>|null
     */
    private function findScheduleReplacement(
        MassCelebration $row,
        array $expectedByKey,
        array $existingByKey
    ): ?array {
        if ($row->celebrated_on === null || $row->slot_id === null) {
            return null;
        }

        $dateStr = $row->celebrated_on->format('Y-m-d');
        $slotId = (string) $row->slot_id;

        foreach ($expectedByKey as $key => $expected) {
            if (($expected['date'] ?? '') !== $dateStr) {
                continue;
            }
            if ((string) ($expected['slot_id'] ?? '') === $slotId) {
                continue;
            }

            $replacementRow = $existingByKey[$key] ?? null;
            $time = substr((string) ($expected['celebrated_at'] ?? ''), 0, 5);

            return [
                'celebration_id' => $replacementRow?->id,
                'celebrated_on' => $dateStr,
                'celebrated_at' => $time,
                'place' => $expected['place'] ?? null,
                'celebrant_name' => $expected['celebrant_name'] ?? null,
                'source_label' => $expected['source_label'] ?? null,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $replacement
     * @return array<string, mixed>
     */
    private function conflictPayload(
        MassCelebration $row,
        string $reasonCode,
        ?array $expected,
        ?array $replacement = null
    ): array {
        $payload = [
            'celebration_id' => $row->id,
            'celebrated_on' => $row->celebrated_on?->format('Y-m-d'),
            'celebrated_at' => $row->celebrated_at,
            'reason_code' => $reasonCode,
            'intention_count' => MassCelebrationEligibility::intentionCount((int) $row->tenant_id, $row->id),
            'proposed' => $expected,
        ];

        if ($replacement !== null) {
            $payload['replacement'] = $replacement;
        }

        return $payload;
    }

    private function fallbackSourceLabel(MassCelebration $row): string
    {
        $time = $row->celebrated_at ? substr((string) $row->celebrated_at, 0, 5) : '';

        return trim(($row->celebrated_on?->format('Y-m-d') ?? '').' '.$time);
    }

    private function fallbackCreatorUserId(int $tenantId): int
    {
        $fromSchedule = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'regular')
            ->value('created_by_user_id');

        return (int) $fromSchedule;
    }
}
