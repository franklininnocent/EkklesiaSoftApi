<?php

namespace Modules\Sacraments\Services\Context;

use Illuminate\Support\Collection;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;

final class SacramentHistoryProvider
{
    /**
     * @return array{
     *     baptism: Collection<int, Sacrament>,
     *     confirmation: Collection<int, Sacrament>,
     *     eucharist: Collection<int, Sacrament>,
     *     marriage_history: Collection<int, Sacrament>
     * }
     */
    public function load(int|string $tenantId, ?string $personId, ?string $familyMemberId): array
    {
        if (($personId === null || $personId === '') && ($familyMemberId === null || $familyMemberId === '')) {
            return [
                'baptism' => collect(),
                'confirmation' => collect(),
                'eucharist' => collect(),
                'marriage_history' => collect(),
            ];
        }

        $query = Sacrament::query()
            ->forTenant($tenantId)
            ->whereIn('status', [SacramentStatus::REGISTERED, SacramentStatus::CONDITIONAL])
            ->whereNull('deleted_at')
            ->with(['sacramentType', 'participants'])
            ->orderByDesc('date_administered');

        if ($familyMemberId !== null && $familyMemberId !== '') {
            $query->linkedToFamilyMember($familyMemberId, $personId);
        } elseif ($personId !== null) {
            $query->where('person_id', $personId);
        }

        $records = $query->get();

        return [
            'baptism' => $this->filterByType($records, SacramentTypeCode::BAPTISM),
            'confirmation' => $this->filterByType($records, SacramentTypeCode::CONFIRMATION),
            'eucharist' => $this->filterByType($records, SacramentTypeCode::EUCHARIST),
            'marriage_history' => $this->filterByType($records, SacramentTypeCode::MATRIMONY),
        ];
    }

    /**
     * @param  Collection<int, Sacrament>  $records
     * @return Collection<int, Sacrament>
     */
    private function filterByType(Collection $records, string $typeCode): Collection
    {
        return $records->filter(function (Sacrament $sacrament) use ($typeCode) {
            $code = $sacrament->sacramentType?->code;

            return SacramentTypeCode::normalize($code) === $typeCode;
        })->values();
    }
}
