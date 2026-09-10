<?php

namespace Modules\EcclesiasticalData\Services\Leadership\Adapters;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Modules\EcclesiasticalData\Contracts\LeadershipAdapterInterface;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;

class BishopAppointmentLeadershipAdapter implements LeadershipAdapterInterface
{
    public function __construct(
        private readonly DioceseLeadershipQueryService $leadershipQuery,
        private readonly BishopFileUploadService $fileUploadService,
    ) {}

    public function supports(LeadershipOfficeCode $officeCode): bool
    {
        return in_array($officeCode, [
            LeadershipOfficeCode::DiocesanBishop,
            LeadershipOfficeCode::AuxiliaryBishop,
            LeadershipOfficeCode::CoadjutorBishop,
        ], true);
    }

    public function getCurrent(LeadershipOfficeCode $officeCode, LeadershipScope $scope): ?LeadershipAssignmentDto
    {
        if ($scope->type !== 'diocese' || ! $scope->id) {
            return null;
        }

        $leadership = $this->leadershipQuery->getCurrentLeadership($scope->id);

        return match ($officeCode) {
            LeadershipOfficeCode::DiocesanBishop => $this->fromPresented(
                $officeCode,
                $scope,
                $leadership['ordinary'] ?? null,
            ),
            LeadershipOfficeCode::AuxiliaryBishop => $this->firstMatchingRole(
                $officeCode,
                $scope,
                $leadership['current_appointments'] ?? collect(),
                CanonicalRole::Auxiliary->value,
            ),
            LeadershipOfficeCode::CoadjutorBishop => $this->firstMatchingRole(
                $officeCode,
                $scope,
                $leadership['current_appointments'] ?? collect(),
                CanonicalRole::Coadjutor->value,
            ),
            default => null,
        };
    }

    public function getOnDate(LeadershipOfficeCode $officeCode, LeadershipScope $scope, string $date): ?LeadershipAssignmentDto
    {
        if ($scope->type !== 'diocese' || ! $scope->id || $officeCode !== LeadershipOfficeCode::DiocesanBishop) {
            return null;
        }

        $appointment = $this->leadershipQuery->getOrdinaryOnDate($scope->id, $date);

        return $appointment ? $this->fromAppointment($officeCode, $scope, $appointment) : null;
    }

    public function getHistory(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator {
        if ($scope->type !== 'diocese' || ! $scope->id) {
            return new Paginator([], 0, $perPage, $page ?? 1);
        }

        $role = match ($officeCode) {
            LeadershipOfficeCode::DiocesanBishop => CanonicalRole::DiocesanBishop,
            LeadershipOfficeCode::AuxiliaryBishop => CanonicalRole::Auxiliary,
            LeadershipOfficeCode::CoadjutorBishop => CanonicalRole::Coadjutor,
            default => null,
        };

        $paginator = $this->leadershipQuery->getAppointmentHistory($scope->id, $role, $perPage, $page);

        return $paginator->through(fn (BishopAppointment $appointment) => $this->fromAppointment(
            $officeCode,
            $scope,
            $appointment,
        )->toArray());
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $appointments
     */
    private function firstMatchingRole(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        iterable $appointments,
        string $role,
    ): ?LeadershipAssignmentDto {
        foreach ($appointments as $appointment) {
            if (($appointment['canonical_role'] ?? null) === $role) {
                return $this->fromPresented($officeCode, $scope, $appointment);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $presented
     */
    private function fromPresented(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        ?array $presented,
    ): ?LeadershipAssignmentDto {
        if ($presented === null || empty($presented['bishop_id'])) {
            return null;
        }

        return new LeadershipAssignmentDto(
            officeCode: $officeCode->value,
            scope: array_merge($scope->toArray(), ['type' => 'diocese']),
            person: [
                'id' => $presented['bishop_id'],
                'full_name' => $presented['bishop_name'] ?? null,
                'title' => $presented['title'] ?? null,
                'photo_url' => $presented['photo_url'] ?? null,
                'photo_public_url' => $presented['photo_public_url'] ?? null,
                'has_photo' => $presented['has_photo'] ?? false,
            ],
            assignment: [
                'id' => $presented['appointment_id'] ?? null,
                'start_date' => $presented['effective_date'] ?? null,
                'end_date' => $presented['ended_date'] ?? null,
                'status' => $presented['appointment_status'] ?? null,
                'appointment_reference' => null,
                'change_reason' => null,
                'canonical_role' => $presented['canonical_role'] ?? null,
                'is_current' => $presented['is_current'] ?? false,
            ],
        );
    }

    private function fromAppointment(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        BishopAppointment $appointment,
    ): LeadershipAssignmentDto {
        $appointment->loadMissing(['bishop.ecclesiasticalTitle', 'ecclesiasticalTitle']);

        return new LeadershipAssignmentDto(
            officeCode: $officeCode->value,
            scope: array_merge($scope->toArray(), ['type' => 'diocese']),
            person: [
                'id' => $appointment->bishop_id,
                'full_name' => $appointment->bishop?->full_name,
                'title' => $appointment->ecclesiasticalTitle?->title,
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
            ],
            assignment: [
                'id' => $appointment->id,
                'start_date' => $appointment->effective_date?->toDateString(),
                'end_date' => $appointment->ended_date?->toDateString(),
                'status' => $appointment->appointment_status?->value ?? $appointment->appointment_status,
                'appointment_reference' => $appointment->source_reference,
                'change_reason' => $appointment->end_reason?->value ?? $appointment->end_reason,
                'canonical_role' => $appointment->canonical_role?->value ?? $appointment->canonical_role,
                'is_current' => $appointment->is_current,
            ],
        );
    }
}
