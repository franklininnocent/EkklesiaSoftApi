<?php

namespace Modules\EcclesiasticalData\Services\Leadership\Adapters;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Storage;
use Modules\EcclesiasticalData\Contracts\LeadershipAdapterInterface;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;
use Modules\Tenants\Models\PopeAssignment;

class PopeLeadershipAdapter implements LeadershipAdapterInterface
{
    public function supports(LeadershipOfficeCode $officeCode): bool
    {
        return $officeCode === LeadershipOfficeCode::Pope;
    }

    public function getCurrent(LeadershipOfficeCode $officeCode, LeadershipScope $scope): ?LeadershipAssignmentDto
    {
        $assignment = PopeAssignment::query()
            ->active()
            ->orderByDesc('start_date')
            ->first();

        if ($assignment === null) {
            return null;
        }

        return $this->fromAssignment($officeCode, $scope, $assignment);
    }

    public function getOnDate(LeadershipOfficeCode $officeCode, LeadershipScope $scope, string $date): ?LeadershipAssignmentDto
    {
        $assignment = PopeAssignment::query()
            ->where('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $date);
            })
            ->orderByDesc('start_date')
            ->first();

        return $assignment ? $this->fromAssignment($officeCode, $scope, $assignment) : null;
    }

    public function getHistory(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator {
        return PopeAssignment::query()
            ->orderByDesc('start_date')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (PopeAssignment $assignment) => $this->fromAssignment(
                $officeCode,
                $scope,
                $assignment,
            )->toArray());
    }

    private function fromAssignment(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        PopeAssignment $assignment,
    ): LeadershipAssignmentDto {
        $photoUrl = $assignment->photo_path
            ? Storage::disk('public')->url($assignment->photo_path)
            : null;

        return new LeadershipAssignmentDto(
            officeCode: $officeCode->value,
            scope: array_merge($scope->toArray(), ['type' => 'global']),
            person: [
                'id' => $assignment->id,
                'full_name' => $assignment->pope_name,
                'title' => $assignment->pope_title,
                'photo_url' => $photoUrl,
                'photo_public_url' => $photoUrl,
                'has_photo' => ! empty($assignment->photo_path),
            ],
            assignment: [
                'id' => $assignment->id,
                'start_date' => $assignment->start_date?->toDateString(),
                'end_date' => $assignment->end_date?->toDateString(),
                'status' => $assignment->status,
                'appointment_reference' => $assignment->appointment_reference,
                'change_reason' => $assignment->change_reason,
            ],
        );
    }
}
