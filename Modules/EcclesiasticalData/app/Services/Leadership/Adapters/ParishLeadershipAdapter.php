<?php

namespace Modules\EcclesiasticalData\Services\Leadership\Adapters;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Modules\EcclesiasticalData\Contracts\LeadershipAdapterInterface;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\LeadershipAssignment;
use Modules\Tenants\Models\LeadershipRole;
use Modules\Tenants\Services\LeadershipDomainService;
use Modules\Tenants\Support\LeadershipAssignmentStatus;

class ParishLeadershipAdapter implements LeadershipAdapterInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_TITLES = [
        LeadershipOfficeCode::ParishPriest->value => ['Pastor'],
        LeadershipOfficeCode::AssociateParishPriest->value => ['Parochial Vicar'],
        LeadershipOfficeCode::ParishAdministrator->value => ['Parochial Administrator'],
    ];

    public function __construct(
        private readonly LeadershipDomainService $leadershipDomain,
    ) {}

    public function supports(LeadershipOfficeCode $officeCode): bool
    {
        return isset(self::ROLE_TITLES[$officeCode->value]);
    }

    public function getCurrent(LeadershipOfficeCode $officeCode, LeadershipScope $scope): ?LeadershipAssignmentDto
    {
        $tenantId = $this->resolveTenantId($scope);
        if ($tenantId === null) {
            return null;
        }

        $current = $this->leadershipDomain->getCurrentLeadership($tenantId);
        $assignment = $this->findActiveAssignment($current['assignments'] ?? collect(), $officeCode);

        return $assignment ? $this->fromPresented($officeCode, $scope, $assignment) : null;
    }

    public function getOnDate(LeadershipOfficeCode $officeCode, LeadershipScope $scope, string $date): ?LeadershipAssignmentDto
    {
        $tenantId = $this->resolveTenantId($scope);
        if ($tenantId === null) {
            return null;
        }

        $history = $this->leadershipDomain->getHistory($tenantId, ['status' => LeadershipAssignmentStatus::ACTIVE], 100);
        $assignment = collect($history->items())
            ->first(function (array $item) use ($officeCode, $date) {
                if (! $this->matchesOffice($item, $officeCode)) {
                    return false;
                }

                $start = $item['start_date'] ?? $item['appointment_date'] ?? null;
                $end = $item['end_date'] ?? null;

                if ($start && $date < $start) {
                    return false;
                }

                if ($end && $date > $end) {
                    return false;
                }

                return true;
            });

        return is_array($assignment)
            ? $this->fromPresented($officeCode, $scope, $assignment)
            : null;
    }

    public function getHistory(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator {
        $tenantId = $this->resolveTenantId($scope);
        if ($tenantId === null) {
            return new Paginator([], 0, $perPage, $page ?? 1);
        }

        $roleTitles = self::ROLE_TITLES[$officeCode->value] ?? [];
        $roleIds = LeadershipRole::query()->whereIn('title', $roleTitles)->pluck('id');

        $query = LeadershipAssignment::query()
            ->forTenant($tenantId)
            ->when($scope->id, fn ($q) => $q->forChurchProfile($scope->id))
            ->whereIn('role_id', $roleIds)
            ->with(['person', 'role'])
            ->orderByDesc('start_date');

        return $query->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (LeadershipAssignment $assignment) => $this->fromModel(
                $officeCode,
                $scope,
                $assignment,
            )->toArray());
    }

    private function resolveTenantId(LeadershipScope $scope): ?int
    {
        if ($scope->tenantId) {
            return $scope->tenantId;
        }

        if ($scope->type === 'parish' && $scope->id) {
            return ChurchProfile::query()->whereKey($scope->id)->value('tenant_id');
        }

        return null;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $assignments
     */
    private function findActiveAssignment(iterable $assignments, LeadershipOfficeCode $officeCode): ?array
    {
        foreach ($assignments as $assignment) {
            if (($assignment['status'] ?? null) === LeadershipAssignmentStatus::ACTIVE
                && $this->matchesOffice($assignment, $officeCode)) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $assignment
     */
    private function matchesOffice(array $assignment, LeadershipOfficeCode $officeCode): bool
    {
        $title = $assignment['role']['title'] ?? null;

        return in_array($title, self::ROLE_TITLES[$officeCode->value] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $presented
     */
    private function fromPresented(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        array $presented,
    ): LeadershipAssignmentDto {
        return new LeadershipAssignmentDto(
            officeCode: $officeCode->value,
            scope: array_merge($scope->toArray(), ['type' => 'parish']),
            person: [
                'id' => $presented['person']['id'] ?? $presented['person_id'] ?? null,
                'full_name' => $presented['person']['full_name'] ?? null,
                'title' => $presented['role']['title'] ?? null,
                'photo_url' => $presented['person']['photo_url'] ?? null,
                'photo_public_url' => $presented['person']['photo_full_url'] ?? null,
                'has_photo' => ! empty($presented['person']['photo_url']),
            ],
            assignment: [
                'id' => $presented['id'] ?? null,
                'start_date' => $presented['start_date'] ?? $presented['appointment_date'] ?? null,
                'end_date' => $presented['end_date'] ?? null,
                'status' => $presented['status'] ?? null,
                'appointment_reference' => $presented['appointment_letter_ref'] ?? null,
                'change_reason' => $presented['exit_reason_code'] ?? null,
            ],
        );
    }

    private function fromModel(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        LeadershipAssignment $assignment,
    ): LeadershipAssignmentDto {
        $assignment->loadMissing(['person', 'role']);

        return new LeadershipAssignmentDto(
            officeCode: $officeCode->value,
            scope: array_merge($scope->toArray(), ['type' => 'parish']),
            person: [
                'id' => $assignment->person_id,
                'full_name' => $assignment->person?->full_name_display,
                'title' => $assignment->role?->title,
                'photo_url' => $assignment->photo_url,
                'photo_public_url' => $assignment->photo_url,
                'has_photo' => ! empty($assignment->photo_url),
            ],
            assignment: [
                'id' => $assignment->id,
                'start_date' => $assignment->start_date?->toDateString(),
                'end_date' => $assignment->end_date?->toDateString(),
                'status' => $assignment->status,
                'appointment_reference' => $assignment->appointment_letter_ref,
                'change_reason' => $assignment->exit_reason_code,
            ],
        );
    }
}
