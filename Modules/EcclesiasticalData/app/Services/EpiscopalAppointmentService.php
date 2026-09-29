<?php

namespace Modules\EcclesiasticalData\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\AppointmentStatus;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class EpiscopalAppointmentService
{
    public function __construct(
        private readonly BishopAppointmentBackfillService $leadershipStateService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $actorId = null): BishopAppointment
    {
        $this->validateTemporalDates($data);

        $role = $this->resolveCanonicalRole($data);
        $effectiveDate = $data['effective_date'] ?? $data['appointed_date'];
        $isFuture = Carbon::parse($effectiveDate)->isAfter(now()->startOfDay());

        if ($role->isOrdinary() && ($data['is_current'] ?? true) && ! $isFuture) {
            $this->assertNoCurrentOrdinary((int) $data['diocese_id']);
        }

        $appointment = BishopAppointment::create([
            'bishop_id' => $data['bishop_id'],
            'diocese_id' => $data['diocese_id'],
            'ecclesiastical_title_id' => $data['ecclesiastical_title_id'] ?? null,
            'canonical_role' => $role->value,
            'appointed_date' => $data['appointed_date'] ?? $effectiveDate,
            'announced_date' => $data['announced_date'] ?? null,
            'effective_date' => $effectiveDate,
            'ordained_date' => $data['ordained_date'] ?? null,
            'installed_date' => $data['installed_date'] ?? null,
            'is_current' => $isFuture ? false : (bool) ($data['is_current'] ?? true),
            'appointment_status' => $isFuture
                ? AppointmentStatus::Future->value
                : ($data['appointment_status'] ?? AppointmentStatus::Current->value),
            'appointment_details' => $data['appointment_details'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_reference' => $data['source_reference'] ?? null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $this->leadershipStateService->refreshDioceseLeadershipState((int) $data['diocese_id']);
        $this->invalidateBishopListCaches((int) $data['bishop_id']);

        return $appointment->fresh(['bishop', 'diocese']);
    }

    public function end(
        BishopAppointment $appointment,
        string $endedDate,
        AppointmentEndReason $reason,
        ?int $actorId = null,
    ): BishopAppointment {
        if ($appointment->ended_date) {
            throw EcclesiasticalDomainException::conflict('Appointment has already ended.');
        }

        $effectiveDate = $appointment->effective_date?->toDateString() ?? $appointment->appointed_date?->toDateString();
        if ($effectiveDate && Carbon::parse($endedDate)->lt(Carbon::parse($effectiveDate))) {
            throw EcclesiasticalDomainException::validation('End date cannot be before the appointment effective date.');
        }

        $appointment->fill([
            'ended_date' => $endedDate,
            'end_reason' => $reason->value,
            'is_current' => false,
            'appointment_status' => AppointmentStatus::Ended->value,
            'updated_by' => $actorId,
        ]);
        $appointment->save();

        $this->leadershipStateService->refreshDioceseLeadershipState((int) $appointment->diocese_id);
        $this->invalidateBishopListCaches((int) $appointment->bishop_id);

        return $appointment->fresh(['bishop', 'diocese']);
    }

    public function activate(BishopAppointment $appointment, ?int $actorId = null): BishopAppointment
    {
        if ($appointment->is_current) {
            return $appointment;
        }

        if ($appointment->appointment_status === AppointmentStatus::Ended) {
            throw EcclesiasticalDomainException::conflict('Cannot activate an ended appointment.');
        }

        if ($appointment->isOrdinaryRole()) {
            $this->assertNoCurrentOrdinary((int) $appointment->diocese_id, $appointment->id);
        }

        $appointment->fill([
            'is_current' => true,
            'appointment_status' => AppointmentStatus::Current->value,
            'updated_by' => $actorId,
        ]);
        $appointment->save();

        $this->leadershipStateService->refreshDioceseLeadershipState((int) $appointment->diocese_id);
        $this->invalidateBishopListCaches((int) $appointment->bishop_id);

        return $appointment->fresh(['bishop', 'diocese']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateTemporalDates(array $data): void
    {
        $effective = $data['effective_date'] ?? $data['appointed_date'] ?? null;
        $installed = $data['installed_date'] ?? null;
        $ended = $data['ended_date'] ?? null;
        $announced = $data['announced_date'] ?? null;

        if ($effective && $installed && Carbon::parse($installed)->lt(Carbon::parse($effective))) {
            throw EcclesiasticalDomainException::validation('Installation date cannot be before effective date.');
        }

        if ($effective && $ended && Carbon::parse($ended)->lt(Carbon::parse($effective))) {
            throw EcclesiasticalDomainException::validation('End date cannot be before effective date.');
        }

        if ($announced && $effective && Carbon::parse($effective)->lt(Carbon::parse($announced))) {
            throw EcclesiasticalDomainException::validation('Effective date cannot be before announced date.');
        }
    }

    public function lockDioceseAppointments(int $dioceseId): void
    {
        BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->select('id')
            ->lockForUpdate()
            ->get();
    }

    private function assertNoCurrentOrdinary(int $dioceseId, ?string $excludeAppointmentId = null): void
    {
        $query = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->ordinary();

        if ($excludeAppointmentId) {
            $query->where('id', '!=', $excludeAppointmentId);
        }

        if ($query->exists()) {
            throw EcclesiasticalDomainException::conflict(
                'Diocese already has a current ordinary. Use the succession endpoint '
                .'(POST /api/ecclesiastical/dioceses/{id}/succession/replace-ordinary) '
                .'or approve a bishop update request to replace the incumbent.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCanonicalRole(array $data): CanonicalRole
    {
        if (! empty($data['canonical_role'])) {
            return $data['canonical_role'] instanceof CanonicalRole
                ? $data['canonical_role']
                : CanonicalRole::from($data['canonical_role']);
        }

        return CanonicalRole::DiocesanBishop;
    }

    private function invalidateBishopListCaches(?int $bishopId = null): void
    {
        Cache::forget('bishops.statistics');

        if ($bishopId) {
            Cache::forget("bishop.{$bishopId}.full");
        }

        foreach (['bishops.paginated.keys', 'bishops.diocese.keys', 'bishops.title.keys'] as $setKey) {
            $keys = Cache::pull($setKey, []);

            foreach ($keys as $key) {
                Cache::forget($key);
            }
        }
    }
}
