<?php

namespace Modules\Sacraments\Services;

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
            $ordinary = $this->leadershipService->getOnDate(
                LeadershipOfficeCode::DiocesanBishop->value,
                LeadershipScope::diocese((int) $profile->archdiocese_id),
                $eventDate,
            );
            $context['diocesan_ordinary'] = $ordinary?->toArray();
        }

        if ($profile) {
            $priest = $this->leadershipService->getOnDate(
                LeadershipOfficeCode::ParishPriest->value,
                LeadershipScope::parish((int) $profile->id, $tenantId),
                $eventDate,
            );
            $context['parish_priest'] = $priest?->toArray();
        }

        return $context;
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
