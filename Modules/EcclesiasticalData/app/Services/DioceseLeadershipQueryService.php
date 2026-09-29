<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Support\DioceseLeadershipState;

class DioceseLeadershipQueryService
{
    /**
     * Prevent recursive repair when sync services re-enter getCurrentLeadership.
     *
     * @var array<int, true>
     */
    private array $repairingDioceses = [];

    public function __construct(
        protected BishopFileUploadService $fileUploadService,
    ) {}

    /**
     * Lazy resolve to avoid constructor cycle:
     * LeadershipQuery → Repair → Succession → ProfileSync → LeadershipQuery
     */
    private function repairService(): DiocesanOrdinaryRepairService
    {
        return app(DiocesanOrdinaryRepairService::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrentLeadership(int $dioceseId): array
    {
        if (! isset($this->repairingDioceses[$dioceseId]) && $this->repairService()->needsOrdinaryRepair($dioceseId)) {
            $this->repairingDioceses[$dioceseId] = true;

            try {
                $this->repairService()->repairDiocese($dioceseId);
            } finally {
                unset($this->repairingDioceses[$dioceseId]);
            }
        }

        $diocese = DioceseManagement::query()->findOrFail($dioceseId);

        $appointments = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->with(['bishop.ecclesiasticalTitle', 'ecclesiasticalTitle'])
            ->orderByRaw("CASE canonical_role
                WHEN 'archbishop' THEN 1
                WHEN 'diocesan_bishop' THEN 2
                WHEN 'coadjutor' THEN 3
                WHEN 'auxiliary' THEN 4
                ELSE 5 END")
            ->get();

        $ordinary = $appointments->first(fn (BishopAppointment $a) => $a->isOrdinaryRole());

        return [
            'diocese_id' => $dioceseId,
            'diocese_name' => $diocese->name,
            'diocese_code' => $diocese->code,
            'leadership_state' => $diocese->leadership_state?->value ?? DioceseLeadershipState::Vacant->value,
            'last_verified_at' => $diocese->last_verified_at,
            'ordinary' => $ordinary ? $this->presentAppointment($ordinary) : null,
            'current_appointments' => $appointments->map(fn (BishopAppointment $a) => $this->presentAppointment($a))->values(),
        ];
    }

    public function getOrdinaryOnDate(int $dioceseId, string $date): ?BishopAppointment
    {
        return BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->ordinary()
            ->where('effective_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('ended_date')
                    ->orWhere('ended_date', '>=', $date);
            })
            ->with(['bishop', 'ecclesiasticalTitle'])
            ->orderByDesc('effective_date')
            ->first();
    }

    public function getAppointmentHistory(
        int $dioceseId,
        ?CanonicalRole $role = null,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator {
        $query = BishopAppointment::query()
            ->where('diocese_id', $dioceseId)
            ->with(['bishop', 'ecclesiasticalTitle'])
            ->orderByDesc('effective_date')
            ->orderByDesc('appointed_date');

        if ($role) {
            $query->where('canonical_role', $role->value);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAppointment(BishopAppointment $appointment): array
    {
        return [
            'appointment_id' => $appointment->id,
            'bishop_id' => $appointment->bishop_id,
            'bishop_name' => $appointment->bishop?->full_name,
            'photo_url' => $this->fileUploadService->resolvePhotoUrl(
                $appointment->bishop?->photo_path,
                $appointment->bishop?->photo_url,
            ),
            'photo_public_url' => $this->fileUploadService->resolvePhotoUrl(
                $appointment->bishop?->photo_path,
                $appointment->bishop?->photo_url,
            ),
            'has_photo' => $this->fileUploadService->hasPhoto(
                $appointment->bishop?->photo_path,
                $appointment->bishop?->photo_url,
            ),
            'canonical_role' => $appointment->canonical_role?->value ?? $appointment->canonical_role,
            'title' => $appointment->ecclesiasticalTitle?->title,
            'announced_date' => $appointment->announced_date?->toDateString(),
            'effective_date' => $appointment->effective_date?->toDateString(),
            'installed_date' => $appointment->installed_date?->toDateString(),
            'ended_date' => $appointment->ended_date?->toDateString(),
            'is_current' => $appointment->is_current,
            'appointment_status' => $appointment->appointment_status?->value ?? $appointment->appointment_status,
        ];
    }
}
