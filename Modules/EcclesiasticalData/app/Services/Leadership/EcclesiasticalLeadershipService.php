<?php

namespace Modules\EcclesiasticalData\Services\Leadership;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Modules\EcclesiasticalData\Contracts\LeadershipAdapterInterface;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;
use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\EcclesiasticalOffice;
use Modules\EcclesiasticalData\Services\Leadership\Adapters\BishopAppointmentLeadershipAdapter;
use Modules\EcclesiasticalData\Services\Leadership\Adapters\ParishLeadershipAdapter;
use Modules\EcclesiasticalData\Services\Leadership\Adapters\PopeLeadershipAdapter;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;

class EcclesiasticalLeadershipService
{
    private const CACHE_TTL = 300;

    /**
     * @var list<LeadershipAdapterInterface>|null
     */
    private ?array $adapters = null;

    public function getCurrent(string $officeCode, LeadershipScope $scope): ?LeadershipAssignmentDto
    {
        $office = $this->resolveOffice($officeCode);
        $adapter = $this->adapterFor($office);

        $cacheKey = $this->cacheKey('current', $officeCode, $scope);

        return Cache::remember($cacheKey, self::CACHE_TTL, fn () => $adapter->getCurrent(
            LeadershipOfficeCode::from($office->office_code),
            $scope,
        ));
    }

    public function getOnDate(string $officeCode, LeadershipScope $scope, string $date): ?LeadershipAssignmentDto
    {
        $office = $this->resolveOffice($officeCode);
        $adapter = $this->adapterFor($office);

        return $adapter->getOnDate(LeadershipOfficeCode::from($office->office_code), $scope, $date);
    }

    public function getHistory(
        string $officeCode,
        LeadershipScope $scope,
        int $perPage = 20,
        ?int $page = null,
    ): LengthAwarePaginator {
        $office = $this->resolveOffice($officeCode);
        $adapter = $this->adapterFor($office);

        return $adapter->getHistory(
            LeadershipOfficeCode::from($office->office_code),
            $scope,
            $perPage,
            $page,
        );
    }

    public function invalidateScope(string $officeCode, LeadershipScope $scope): void
    {
        Cache::forget($this->cacheKey('current', $officeCode, $scope));
    }

    public function invalidateDiocese(int $dioceseId): void
    {
        foreach ([
            LeadershipOfficeCode::DiocesanBishop->value,
            LeadershipOfficeCode::AuxiliaryBishop->value,
            LeadershipOfficeCode::CoadjutorBishop->value,
        ] as $officeCode) {
            $this->invalidateScope($officeCode, LeadershipScope::diocese($dioceseId));
        }
    }

    public function invalidateParish(int $churchProfileId, ?int $tenantId = null): void
    {
        $scope = LeadershipScope::parish($churchProfileId, $tenantId);

        foreach ([
            LeadershipOfficeCode::ParishPriest->value,
            LeadershipOfficeCode::AssociateParishPriest->value,
            LeadershipOfficeCode::ParishAdministrator->value,
        ] as $officeCode) {
            $this->invalidateScope($officeCode, $scope);
        }
    }

    public function invalidatePope(): void
    {
        $this->invalidateScope(LeadershipOfficeCode::Pope->value, LeadershipScope::global());
    }

    private function resolveOffice(string $officeCode): EcclesiasticalOffice
    {
        $office = EcclesiasticalOffice::query()
            ->where('office_code', $officeCode)
            ->where('is_active', true)
            ->first();

        if (! $office) {
            throw EcclesiasticalDomainException::notFound("Unknown ecclesiastical office: {$officeCode}");
        }

        return $office;
    }

    private function adapterFor(EcclesiasticalOffice $office): LeadershipAdapterInterface
    {
        $code = LeadershipOfficeCode::from($office->office_code);

        foreach ($this->adapters() as $adapter) {
            if ($adapter->supports($code)) {
                return $adapter;
            }
        }

        throw EcclesiasticalDomainException::validation("No adapter registered for office {$office->office_code}");
    }

    /**
     * @return list<LeadershipAdapterInterface>
     */
    private function adapters(): array
    {
        return $this->adapters ??= [
            app(BishopAppointmentLeadershipAdapter::class),
            app(ParishLeadershipAdapter::class),
            app(PopeLeadershipAdapter::class),
        ];
    }

    private function cacheKey(string $operation, string $officeCode, LeadershipScope $scope): string
    {
        return sprintf(
            'ecclesiastical.leadership.%s.%s.%s.%s.%s',
            $operation,
            $officeCode,
            $scope->type,
            $scope->id ?? 'null',
            $scope->tenantId ?? 'null',
        );
    }
}
