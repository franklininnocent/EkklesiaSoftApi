<?php

namespace Modules\Sacraments\Services;

use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Services\Leadership\EcclesiasticalLeadershipService;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;
use Modules\EcclesiasticalData\Support\LeadershipScope;
use Modules\Sacraments\Certificates\DenominationMapper;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\Tenant;

class SacramentLeadershipContextBuilder
{
    public function __construct(
        private readonly EcclesiasticalLeadershipService $leadershipService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, ?string $eventDate = null): array
    {
        $eventDate = $eventDate ?? now()->toDateString();
        $profile = ChurchProfile::query()->where('tenant_id', $tenantId)->first();

        $context = [
            'schema_version' => 1,
            'captured_at' => now()->toIso8601String(),
            'event_date' => $eventDate,
            'church' => $this->freezeChurch($tenantId),
            'diocesan_ordinary' => null,
            'parish_priest' => null,
        ];

        if ($profile?->archdiocese_id) {
            $context['diocesan_ordinary'] = $this->officeHolderOnDate(
                LeadershipOfficeCode::DiocesanBishop->value,
                LeadershipScope::diocese((int) $profile->archdiocese_id),
                $eventDate,
            );
        }

        if ($profile) {
            $context['parish_priest'] = $this->officeHolderOnDate(
                LeadershipOfficeCode::ParishPriest->value,
                LeadershipScope::parish((int) $profile->id, $tenantId),
                $eventDate,
            );
        }

        return $context;
    }

    /**
     * Certificate freeze must not fail registration when office catalog rows are absent.
     *
     * @return array<string, mixed>|null
     */
    private function officeHolderOnDate(string $officeCode, LeadershipScope $scope, string $eventDate): ?array
    {
        try {
            return $this->leadershipService->getOnDate($officeCode, $scope, $eventDate)?->toArray();
        } catch (EcclesiasticalDomainException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function freezeChurch(int $tenantId): array
    {
        $tenant = Tenant::query()
            ->with(['churchProfile.denomination', 'churchProfile.archdiocese', 'addresses'])
            ->find($tenantId);

        $profile = $tenant?->churchProfile;
        $denominationCode = $profile?->denomination?->code ?? 'GENERIC';
        $address = $tenant?->addresses
            ->firstWhere('address_type', 'official')
            ?? $tenant?->addresses->first();
        $addressText = null;
        if ($address) {
            $addressText = trim(implode(', ', array_filter([
                $address->line1 ?? null,
                $address->line2 ?? null,
                $address->city ?? null,
                $address->state_province ?? null,
                $address->country ?? null,
                $address->pin_zip_code ?? null,
            ])));
        }

        return [
            'name' => $tenant?->name ?? 'Parish Church',
            'diocese' => $profile?->archdiocese?->name,
            'address' => $addressText ?: null,
            'logo_url' => null,
            'logo_hash' => null,
            'seal_url' => null,
            'denomination_code' => $denominationCode,
            'denomination_type' => DenominationMapper::map(is_string($denominationCode) ? $denominationCode : null),
            'parish_code' => $tenant?->slug ?: (string) $tenantId,
        ];
    }
}
