<?php

namespace Modules\Family\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Tenant-scoped sacrament receipt lookup for member progression / gap analytics.
 */
class SacramentReceiptIndexBuilder
{
    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>
     */
    public function build(int|string $tenantId, array $restrictedTypeCodes = []): array
    {
        $query = DB::table('sacraments as s')
            ->join('sacrament_types as st', 'st.id', '=', 's.sacrament_type_id')
            ->leftJoin('sacrament_participants as sp', function ($join) {
                $join->on('sp.sacrament_id', '=', 's.id')
                    ->whereNull('sp.deleted_at');
            })
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.status', ['registered', 'conditional'])
            ->whereNull('s.deleted_at');

        if ($restrictedTypeCodes !== []) {
            $codes = $this->expandRestrictedCodes($restrictedTypeCodes);
            $query->whereRaw(
                'UPPER(st.code) NOT IN ('.implode(',', array_fill(0, count($codes), '?')).')',
                $codes
            );
        }

        $records = $query->get([
            's.person_id',
            'st.code as type_code',
            'sp.family_member_id',
            'sp.person_id as participant_person_id',
            'sp.role',
        ]);

        $index = [];
        foreach ($records as $row) {
            $code = $this->normalizeCode((string) $row->type_code);
            if ($code === '') {
                continue;
            }

            if (! isset($index[$code])) {
                $index[$code] = ['person_ids' => [], 'member_ids' => []];
            }

            if ($row->person_id) {
                $index[$code]['person_ids'][(string) $row->person_id] = true;
            }

            if (! in_array($row->role, ['recipient', 'bride', 'groom', 'candidate'], true)) {
                continue;
            }

            if ($row->family_member_id) {
                $index[$code]['member_ids'][(string) $row->family_member_id] = true;
            }

            if ($row->participant_person_id) {
                $index[$code]['person_ids'][(string) $row->participant_person_id] = true;
            }
        }

        return $index;
    }

    public function normalizeCode(string $code): string
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($code)));

        return match ($normalized) {
            'FIRST_COMMUNION', 'FIRSTCOMMUNION' => SacramentTypeCode::EUCHARIST,
            'MARRIAGE', 'WEDDING' => SacramentTypeCode::MATRIMONY,
            'HOLYORDERS', 'ORDINATION' => 'HOLY_ORDERS',
            default => $normalized,
        };
    }

    /**
     * @param  list<string>  $restrictedTypeCodes
     * @return list<string>
     */
    private function expandRestrictedCodes(array $restrictedTypeCodes): array
    {
        $codes = [];
        foreach ($restrictedTypeCodes as $code) {
            $normalized = SacramentTypeCode::normalize((string) $code) ?? strtoupper((string) $code);
            $codes[] = $normalized;
            if ($normalized === SacramentTypeCode::RECONCILIATION) {
                $codes = array_merge($codes, ['RECONCILIATION', 'CONFESSION', 'PENANCE']);
            }
        }

        return array_values(array_unique(array_map('strtoupper', $codes)));
    }
}
