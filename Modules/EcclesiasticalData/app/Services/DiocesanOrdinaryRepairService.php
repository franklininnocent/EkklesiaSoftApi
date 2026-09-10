<?php

namespace Modules\EcclesiasticalData\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\Tenants\Services\ChurchProfileBishopSyncService;

class DiocesanOrdinaryRepairService
{
    private const INACTIVE_BISHOP_STATUSES = ['retired', 'deceased', 'transferred', 'emeritus', 'inactive'];

    public function __construct(
        private readonly EpiscopalAppointmentService $appointmentService,
        private readonly BishopAppointmentBackfillService $leadershipStateService,
        private readonly ChurchProfileBishopSyncService $churchProfileBishopSync,
    ) {}

    /**
     * Lazy resolve to avoid constructor cycle with DioceseLeadershipQueryService.
     */
    private function successionService(): SuccessionService
    {
        return app(SuccessionService::class);
    }

    public function needsOrdinaryRepair(int $dioceseId): bool
    {
        $currentOrdinary = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->ordinary()
            ->with('bishop:id,status')
            ->first();

        if ($currentOrdinary && $this->bishopStatusIsInactive($currentOrdinary->bishop?->status)) {
            return true;
        }

        if (! $currentOrdinary) {
            return BishopManagement::query()
                ->where('archdiocese_id', $dioceseId)
                ->where('status', 'active')
                ->exists();
        }

        return false;
    }

    /**
     * @return array{ordinaries_ended: int, successions_applied: int, profiles_synced: int}
     */
    public function repairDiocese(int $dioceseId, bool $dryRun = false): array
    {
        $stats = [
            'ordinaries_ended' => 0,
            'successions_applied' => 0,
            'profiles_synced' => 0,
        ];

        $successorEffectiveDate = BishopManagement::query()
            ->where('archdiocese_id', $dioceseId)
            ->where('status', 'active')
            ->orderByDesc('appointed_date')
            ->orderByDesc('id')
            ->value('appointed_date');

        $staleOrdinaries = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->ordinary()
            ->with('bishop')
            ->get()
            ->filter(fn (BishopAppointment $appointment) => $this->bishopStatusIsInactive($appointment->bishop?->status));

        foreach ($staleOrdinaries as $stale) {
            if ($dryRun) {
                $stats['ordinaries_ended']++;

                continue;
            }

            $endDate = $stale->bishop?->retired_date?->toDateString()
                ?? ($successorEffectiveDate
                    ? Carbon::parse($successorEffectiveDate)->subDay()->toDateString()
                    : now()->toDateString());

            $this->appointmentService->end(
                $stale,
                $endDate,
                $this->endReasonForBishopStatus($stale->bishop?->status),
            );
            $stats['ordinaries_ended']++;
        }

        $currentOrdinaries = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->ordinary()
            ->orderByDesc('effective_date')
            ->orderByDesc('appointed_date')
            ->get();

        if ($currentOrdinaries->count() > 1) {
            $keeper = $currentOrdinaries->first();

            foreach ($currentOrdinaries->skip(1) as $duplicate) {
                if ($dryRun) {
                    $stats['ordinaries_ended']++;

                    continue;
                }

                $this->appointmentService->end(
                    $duplicate,
                    $this->resolveDuplicateEndDate($duplicate, $keeper),
                    AppointmentEndReason::Correction,
                );
                $stats['ordinaries_ended']++;
            }
        }

        $hasCurrentOrdinary = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->ordinary()
            ->exists();

        if (! $hasCurrentOrdinary) {
            $successor = BishopManagement::query()
                ->where('archdiocese_id', $dioceseId)
                ->where('status', 'active')
                ->orderByDesc('appointed_date')
                ->orderByDesc('id')
                ->first();

            if ($successor) {
                if ($dryRun) {
                    $stats['successions_applied']++;
                } else {
                    $this->successionService()->replaceCurrentOrdinary(
                        $dioceseId,
                        $successor,
                        [
                            'effective_date' => $successor->appointed_date?->toDateString() ?? now()->toDateString(),
                        ],
                    );
                    $stats['successions_applied']++;
                }
            }
        }

        if (! $dryRun) {
            $this->leadershipStateService->refreshDioceseLeadershipState($dioceseId);
            // Pass bishop_id explicitly so profile sync does not re-enter getCurrentLeadership.
            $ordinaryBishopId = BishopAppointment::query()
                ->where('diocese_id', $dioceseId)
                ->where('is_current', true)
                ->ordinary()
                ->value('bishop_id');
            $stats['profiles_synced'] = $this->churchProfileBishopSync->syncDioceseProfilesToCurrentOrdinary(
                $dioceseId,
                $ordinaryBishopId !== null ? (int) $ordinaryBishopId : null,
            );
        }

        return $stats;
    }

    /**
     * @return array{dioceses_processed: int, ordinaries_ended: int, profiles_synced: int}
     */
    public function repair(bool $dryRun = false): array
    {
        $stats = [
            'dioceses_processed' => 0,
            'ordinaries_ended' => 0,
            'profiles_synced' => 0,
        ];

        $dioceseIds = DB::table('archdioceses')->pluck('id');

        foreach ($dioceseIds as $dioceseId) {
            $dioceseId = (int) $dioceseId;
            $stats['dioceses_processed']++;

            $currentOrdinaries = BishopAppointment::query()
                ->where('diocese_id', $dioceseId)
                ->where('is_current', true)
                ->ordinary()
                ->orderByDesc('effective_date')
                ->orderByDesc('appointed_date')
                ->get();

            if ($currentOrdinaries->count() > 1) {
                $keeper = $currentOrdinaries->first();

                foreach ($currentOrdinaries->skip(1) as $duplicate) {
                    if ($dryRun) {
                        $stats['ordinaries_ended']++;

                        continue;
                    }

                    $endDate = $this->resolveDuplicateEndDate($duplicate, $keeper);
                    $this->appointmentService->end(
                        $duplicate,
                        $endDate,
                        AppointmentEndReason::Correction,
                    );
                    $stats['ordinaries_ended']++;
                }
            }

            if (! $dryRun) {
                $this->leadershipStateService->refreshDioceseLeadershipState($dioceseId);
                $stats['profiles_synced'] += $this->churchProfileBishopSync->syncDioceseProfilesToCurrentOrdinary($dioceseId);
            }
        }

        return $stats;
    }

    private function resolveDuplicateEndDate(BishopAppointment $duplicate, BishopAppointment $keeper): string
    {
        $keeperEffective = $keeper->effective_date?->toDateString()
            ?? $keeper->appointed_date?->toDateString()
            ?? now()->toDateString();

        $duplicateEffective = $duplicate->effective_date?->toDateString()
            ?? $duplicate->appointed_date?->toDateString()
            ?? now()->toDateString();

        if (Carbon::parse($duplicateEffective)->gte(Carbon::parse($keeperEffective))) {
            return $keeperEffective;
        }

        return Carbon::parse($keeperEffective)->subDay()->toDateString();
    }

    private function bishopStatusIsInactive(?string $status): bool
    {
        return $status !== null && in_array($status, self::INACTIVE_BISHOP_STATUSES, true);
    }

    private function endReasonForBishopStatus(?string $status): AppointmentEndReason
    {
        return match ($status) {
            'deceased' => AppointmentEndReason::Death,
            'transferred' => AppointmentEndReason::Transfer,
            default => AppointmentEndReason::Retirement,
        };
    }
}
