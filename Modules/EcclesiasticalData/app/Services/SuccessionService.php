<?php

namespace Modules\EcclesiasticalData\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\AppointmentStatus;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\Tenants\Services\ChurchProfileBishopSyncService;

class SuccessionService
{
    public function __construct(
        private readonly EpiscopalAppointmentService $appointmentService,
        private readonly BishopNameNormalizer $nameNormalizer,
        private readonly BishopService $bishopService,
        private readonly ChurchProfileBishopSyncService $churchProfileBishopSync,
        private readonly EcclesiasticalLeadershipService $leadershipService,
    ) {}

    /**
     * Replace the current diocesan ordinary with a new bishop appointment.
     *
     * @param  array<string, mixed>  $appointmentData
     * @return array{ended: ?BishopAppointment, created: BishopAppointment, bishop: BishopManagement}
     */
    public function replaceCurrentOrdinary(
        int $dioceseId,
        BishopManagement $bishop,
        array $appointmentData,
        ?int $actorId = null,
        ?AppointmentEndReason $endReason = null,
    ): array {
        return DB::transaction(function () use ($dioceseId, $bishop, $appointmentData, $actorId, $endReason) {
            $this->appointmentService->lockDioceseAppointments($dioceseId);

            $role = $this->resolveOrdinaryRole($appointmentData);
            $effectiveDate = $appointmentData['effective_date']
                ?? $appointmentData['appointed_date']
                ?? now()->toDateString();

            $isFuture = Carbon::parse($effectiveDate)->isAfter(now()->startOfDay());
            $ended = null;

            if (! $isFuture) {
                $currentOrdinary = BishopAppointment::query()
                    ->where('diocese_id', $dioceseId)
                    ->where('is_current', true)
                    ->ordinary()
                    ->with('bishop:id,is_current,status,full_name')
                    ->first();

                if ($currentOrdinary && (int) $currentOrdinary->bishop_id !== (int) $bishop->id) {
                    $ended = $this->appointmentService->end(
                        $currentOrdinary,
                        Carbon::parse($effectiveDate)->subDay()->toDateString(),
                        $endReason ?? AppointmentEndReason::Transfer,
                        $actorId,
                    );

                    $this->syncLegacyBishopFlags($currentOrdinary->bishop, false, $endReason);
                }
            }

            $created = $this->appointmentService->create(array_merge($appointmentData, [
                'bishop_id' => $bishop->id,
                'diocese_id' => $dioceseId,
                'canonical_role' => $role->value,
                'effective_date' => $effectiveDate,
                'appointed_date' => $appointmentData['appointed_date'] ?? $effectiveDate,
                'is_current' => ! $isFuture,
                'appointment_status' => $isFuture
                    ? AppointmentStatus::Future->value
                    : AppointmentStatus::Current->value,
            ]), $actorId);

            if (! $isFuture) {
                $this->syncLegacyBishopRecord($bishop, $dioceseId, $appointmentData, true);
            }

            $this->bishopService->invalidateListCaches((string) $bishop->id);
            $this->churchProfileBishopSync->syncAfterSuccession(
                $dioceseId,
                $ended?->bishop_id ? (int) $ended->bishop_id : null,
                (int) $bishop->id,
            );
            $this->leadershipService->invalidateDiocese($dioceseId);

            return [
                'ended' => $ended,
                'created' => $created,
                'bishop' => $bishop->fresh(),
            ];
        });
    }

    /**
     * Activate a future ordinary appointment and end the incumbent.
     */
    public function activateOrdinarySuccession(
        BishopAppointment $futureAppointment,
        ?int $actorId = null,
        ?AppointmentEndReason $endReason = null,
    ): array {
        if (! $futureAppointment->isOrdinaryRole()) {
            throw EcclesiasticalDomainException::validation('Only ordinary appointments can be activated through succession.');
        }

        return DB::transaction(function () use ($futureAppointment, $actorId, $endReason) {
            $dioceseId = (int) $futureAppointment->diocese_id;
            $this->appointmentService->lockDioceseAppointments($dioceseId);

            $effectiveDate = $futureAppointment->effective_date?->toDateString()
                ?? $futureAppointment->appointed_date?->toDateString()
                ?? now()->toDateString();

            $ended = null;
            $currentOrdinary = BishopAppointment::query()
                ->where('diocese_id', $dioceseId)
                ->where('is_current', true)
                ->ordinary()
                ->where('id', '!=', $futureAppointment->id)
                ->with('bishop:id,is_current,status,full_name')
                ->first();

            if ($currentOrdinary) {
                $ended = $this->appointmentService->end(
                    $currentOrdinary,
                    Carbon::parse($effectiveDate)->subDay()->toDateString(),
                    $endReason ?? AppointmentEndReason::AppointmentEnded,
                    $actorId,
                );
                $this->syncLegacyBishopFlags($currentOrdinary->bishop, false, $endReason);
            }

            $activated = $this->appointmentService->activate($futureAppointment, $actorId);
            $this->syncLegacyBishopRecord(
                $activated->bishop,
                $dioceseId,
                ['effective_date' => $effectiveDate],
                true,
            );

            $this->bishopService->invalidateListCaches((string) $activated->bishop_id);
            $this->churchProfileBishopSync->syncAfterSuccession(
                $dioceseId,
                $ended?->bishop_id ? (int) $ended->bishop_id : null,
                (int) $activated->bishop_id,
            );
            $this->leadershipService->invalidateDiocese($dioceseId);

            return [
                'ended' => $ended,
                'activated' => $activated,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     */
    private function resolveOrdinaryRole(array $appointmentData): CanonicalRole
    {
        if (! empty($appointmentData['canonical_role'])) {
            $role = $appointmentData['canonical_role'] instanceof CanonicalRole
                ? $appointmentData['canonical_role']
                : CanonicalRole::from($appointmentData['canonical_role']);

            if (! $role->isOrdinary()) {
                throw EcclesiasticalDomainException::validation('Succession requires an ordinary canonical role.');
            }

            return $role;
        }

        return CanonicalRole::DiocesanBishop;
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     */
    private function syncLegacyBishopRecord(
        BishopManagement $bishop,
        int $dioceseId,
        array $appointmentData,
        bool $isCurrent,
    ): void {
        $bishop->update([
            'archdiocese_id' => $dioceseId,
            'is_current' => $isCurrent,
            'appointed_date' => $appointmentData['installed_date']
                ?? $appointmentData['effective_date']
                ?? $appointmentData['appointed_date']
                ?? $bishop->appointed_date,
            'status' => $isCurrent ? 'active' : $bishop->status,
            'normalized_name' => $this->nameNormalizer->normalize($bishop->full_name),
        ]);
    }

    private function syncLegacyBishopFlags(
        ?BishopManagement $bishop,
        bool $isCurrent,
        ?AppointmentEndReason $endReason = null,
    ): void {
        if (! $bishop) {
            return;
        }

        $bishop->update([
            'is_current' => $isCurrent,
            'status' => $isCurrent ? 'active' : $this->personStatusForEndReason($bishop, $endReason),
        ]);
    }

    private function personStatusForEndReason(
        BishopManagement $bishop,
        ?AppointmentEndReason $endReason,
    ): string {
        if ($bishop->status !== 'active') {
            return $bishop->status;
        }

        return match ($endReason) {
            AppointmentEndReason::Retirement => 'retired',
            AppointmentEndReason::Transfer => 'transferred',
            AppointmentEndReason::Death => 'deceased',
            default => 'emeritus',
        };
    }
}
