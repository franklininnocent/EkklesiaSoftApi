<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\MassIntentions\Support\MassSuppressionReason;

final class MassCelebrationEligibility
{
    /**
     * @param  array{date: string, slot_id: string, celebrated_at: string, place?: ?string, celebrant_name?: ?string}  $expected
     */
    public static function isMetadataOnlyScheduleDelta(MassCelebration $celebration, array $expected): bool
    {
        $rowDate = $celebration->celebrated_on?->format('Y-m-d');
        $expectedDate = (string) ($expected['date'] ?? '');
        if ($rowDate === null || $rowDate !== $expectedDate) {
            return false;
        }

        $rowTime = $celebration->celebrated_at ? substr((string) $celebration->celebrated_at, 0, 5) : null;
        $expTime = substr((string) $expected['celebrated_at'], 0, 5);
        if ($rowTime !== $expTime) {
            return false;
        }

        return (string) $celebration->place !== (string) ($expected['place'] ?? '')
            || (string) $celebration->celebrant_name !== (string) ($expected['celebrant_name'] ?? '');
    }

    /**
     * Place/priest-only schedule sync on the same slot/date/time (intentions may remain assigned).
     *
     * @param  array{date: string, slot_id: string, celebrated_at: string, place?: ?string, celebrant_name?: ?string}  $expected
     */
    public static function canApplyMetadataOnlyReconciliation(
        MassCelebration $celebration,
        array $expected,
        int $tenantId,
        ?Carbon $applyFrom = null
    ): bool {
        if (! self::isMetadataOnlyScheduleDelta($celebration, $expected)) {
            return false;
        }

        return self::passesStructuralReconciliationGuards($celebration, $tenantId, $applyFrom);
    }

    public static function isEligibleForReconciliation(
        MassCelebration $celebration,
        int $tenantId,
        ?Carbon $applyFrom = null
    ): bool {
        if (! self::passesStructuralReconciliationGuards($celebration, $tenantId, $applyFrom)) {
            return false;
        }

        if (self::hasActiveIntentionAssignment($tenantId, $celebration->id)) {
            return false;
        }

        if (self::hasSaidFulfilment($tenantId, $celebration->id)) {
            return false;
        }

        return true;
    }

    private static function passesStructuralReconciliationGuards(
        MassCelebration $celebration,
        int $tenantId,
        ?Carbon $applyFrom = null
    ): bool {
        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();

        if ($celebration->celebrated_on === null || $celebration->celebrated_on->lt($today)) {
            return false;
        }

        if ($applyFrom !== null && $celebration->celebrated_on->lt($applyFrom->copy()->startOfDay())) {
            return false;
        }

        if ($celebration->status === 'cancelled') {
            return false;
        }

        if ($celebration->generation_status === MassGenerationStatus::SUPPRESSED) {
            return false;
        }

        if ($celebration->generation_status === MassGenerationStatus::CONFLICT) {
            return false;
        }

        if ($celebration->is_exception) {
            return false;
        }

        if ($celebration->suppression_reason === MassSuppressionReason::USER_CANCELLED) {
            return false;
        }

        return true;
    }

    public static function hasActiveIntentionAssignment(int $tenantId, string $celebrationId): bool
    {
        return DB::table('mass_intention_assignments as a')
            ->join('mass_intention_obligations as o', 'o.id', '=', 'a.obligation_id')
            ->where('a.tenant_id', $tenantId)
            ->where('a.celebration_id', $celebrationId)
            ->whereNull('a.unassigned_at')
            ->exists();
    }

    public static function intentionCount(int $tenantId, string $celebrationId): int
    {
        return (int) DB::table('mass_intention_assignments')
            ->where('tenant_id', $tenantId)
            ->where('celebration_id', $celebrationId)
            ->whereNull('unassigned_at')
            ->count(DB::raw('distinct obligation_id'));
    }

    private static function hasSaidFulfilment(int $tenantId, string $celebrationId): bool
    {
        return DB::table('mass_intention_fulfilments')
            ->where('tenant_id', $tenantId)
            ->where('celebration_id', $celebrationId)
            ->whereNull('undone_at')
            ->exists();
    }

    public static function isOperational(MassCelebration $celebration): bool
    {
        return $celebration->status === 'scheduled'
            && $celebration->generation_status === MassGenerationStatus::ACTIVE;
    }

    public static function isEligibleForIntentionAssignment(MassCelebration $celebration): bool
    {
        if ($celebration->status !== 'scheduled') {
            return false;
        }

        if ($celebration->generation_status !== MassGenerationStatus::ACTIVE) {
            return false;
        }

        if ($celebration->suppression_reason !== null && $celebration->suppression_reason !== '') {
            return false;
        }

        return true;
    }

    /**
     * @return array{id: string, celebrated_on: ?string, celebrated_at: ?string}
     */
    public static function massSnapshot(MassCelebration $celebration): array
    {
        $time = $celebration->celebrated_at;
        $timeStr = $time !== null ? substr((string) $time, 0, 5) : null;

        return [
            'id' => $celebration->id,
            'celebrated_on' => $celebration->celebrated_on?->format('Y-m-d'),
            'celebrated_at' => $timeStr,
        ];
    }
}
