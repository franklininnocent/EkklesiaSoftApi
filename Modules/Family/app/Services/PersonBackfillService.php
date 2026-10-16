<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;

/**
 * Deterministic 1:1 FamilyMember → Person backfill. Never merges similar names.
 */
class PersonBackfillService
{
    /**
     * @return array{created:int, skipped:int, linked_sacraments:int}
     */
    public function run(?int $tenantId = null): array
    {
        $created = 0;
        $skipped = 0;

        $query = FamilyMember::query()
            ->withTrashed()
            ->with('family')
            ->whereNull('person_id')
            ->orderBy('created_at');

        if ($tenantId !== null) {
            $query->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId));
        }

        $query->chunkById(200, function ($members) use (&$created, &$skipped) {
            foreach ($members as $member) {
                $tenantIdForMember = $member->family?->tenant_id;
                if (! $tenantIdForMember) {
                    $skipped++;

                    continue;
                }

                $person = Person::query()->create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantIdForMember,
                    'first_name' => $member->first_name,
                    'middle_name' => $member->middle_name,
                    'last_name' => $member->last_name,
                    'date_of_birth' => $member->date_of_birth,
                    'gender' => $member->gender,
                    'phone' => $member->phone,
                    'email' => $member->email,
                    'status' => $member->status === 'deceased' ? 'deceased' : 'active',
                    'created_by' => $member->created_by,
                    'updated_by' => $member->updated_by,
                ]);

                $member->person_id = $person->id;
                $member->saveQuietly();
                $created++;
            }
        });

        $linked = $this->backfillSacramentPersonIds($tenantId);

        return [
            'created' => $created,
            'skipped' => $skipped,
            'linked_sacraments' => $linked,
        ];
    }

    private function backfillSacramentPersonIds(?int $tenantId): int
    {
        $sql = '
            UPDATE sacraments s
            SET person_id = fm.person_id
            FROM sacrament_participants sp
            INNER JOIN family_members fm ON fm.id = sp.family_member_id
            WHERE sp.sacrament_id = s.id
              AND sp.role = \'recipient\'
              AND sp.deleted_at IS NULL
              AND s.person_id IS NULL
              AND fm.person_id IS NOT NULL
        ';

        $bindings = [];
        if ($tenantId !== null) {
            $sql .= ' AND s.tenant_id = ?';
            $bindings[] = $tenantId;
        }

        if (DB::getDriverName() === 'pgsql') {
            return (int) DB::update($sql, $bindings);
        }

        $updated = 0;
        $rows = DB::table('sacrament_participants as sp')
            ->join('family_members as fm', 'fm.id', '=', 'sp.family_member_id')
            ->join('sacraments as s', 's.id', '=', 'sp.sacrament_id')
            ->where('sp.role', 'recipient')
            ->whereNull('sp.deleted_at')
            ->whereNull('s.person_id')
            ->whereNotNull('fm.person_id')
            ->when($tenantId !== null, fn ($q) => $q->where('s.tenant_id', $tenantId))
            ->select('s.id', 'fm.person_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('sacraments')->where('id', $row->id)->update(['person_id' => $row->person_id]);
            $updated++;
        }

        DB::table('sacrament_participants as sp')
            ->join('family_members as fm', 'fm.id', '=', 'sp.family_member_id')
            ->whereNull('sp.person_id')
            ->whereNotNull('fm.person_id')
            ->when($tenantId !== null, fn ($q) => $q->where('sp.tenant_id', $tenantId))
            ->orderBy('sp.id')
            ->chunkById(200, function ($chunk) {
                foreach ($chunk as $row) {
                    DB::table('sacrament_participants')
                        ->where('id', $row->id)
                        ->update(['person_id' => $row->person_id]);
                }
            }, 'sp.id', 'id');

        return $updated;
    }
}
