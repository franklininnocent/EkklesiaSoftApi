<?php

namespace Modules\EcclesiasticalData\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;

interface LeadershipAdapterInterface
{
    public function supports(LeadershipOfficeCode $officeCode): bool;

    public function getCurrent(LeadershipOfficeCode $officeCode, LeadershipScope $scope): ?LeadershipAssignmentDto;

    public function getOnDate(LeadershipOfficeCode $officeCode, LeadershipScope $scope, string $date): ?LeadershipAssignmentDto;

    public function getHistory(
        LeadershipOfficeCode $officeCode,
        LeadershipScope $scope,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator;
}
