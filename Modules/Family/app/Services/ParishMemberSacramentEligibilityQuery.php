<?php

namespace Modules\Family\Services;

use Carbon\Carbon;
use Modules\Family\Models\FamilyMember;

/**
 * Read-only parish member rows for sacrament participation / gap analytics.
 */
class ParishMemberSacramentEligibilityQuery
{
    /**
     * @return list<array{
     *     id: string,
     *     person_id: ?string,
     *     family_id: string,
     *     age: ?int,
     *     gender: ?string,
     *     marital_status: ?string,
     *     date_of_birth: ?string,
     *     baptism_date: ?string,
     *     first_communion_date: ?string,
     *     confirmation_date: ?string,
     *     marriage_date: ?string
     * }>
     */
    public function eligibleMembers(int $tenantId, ?string $bccId = null): array
    {
        $query = FamilyMember::query()
            ->join('families', 'families.id', '=', 'family_members.family_id')
            ->where('family_members.tenant_id', $tenantId)
            ->where('family_members.status', 'active')
            ->whereNull('family_members.deceased_date')
            ->whereNull('family_members.deleted_at')
            ->where('families.status', 'active')
            ->whereNull('families.deleted_at');

        if ($bccId !== null) {
            $query->where('families.bcc_id', $bccId);
        }

        return $query
            ->get([
                'family_members.id',
                'family_members.family_id',
                'family_members.person_id',
                'family_members.date_of_birth',
                'family_members.gender',
                'family_members.marital_status',
                'family_members.baptism_date',
                'family_members.first_communion_date',
                'family_members.confirmation_date',
                'family_members.marriage_date',
            ])
            ->map(function (FamilyMember $member) {
                $age = null;
                if ($member->date_of_birth !== null) {
                    $age = (int) $member->date_of_birth->diffInYears(Carbon::today());
                }

                return [
                    'id' => (string) $member->id,
                    'family_id' => (string) $member->family_id,
                    'person_id' => $member->person_id ? (string) $member->person_id : null,
                    'age' => $age,
                    'gender' => $member->gender ? strtolower((string) $member->gender) : null,
                    'marital_status' => $member->marital_status ? strtolower((string) $member->marital_status) : null,
                    'date_of_birth' => $member->date_of_birth?->toDateString(),
                    'baptism_date' => $member->baptism_date?->toDateString(),
                    'first_communion_date' => $member->first_communion_date?->toDateString(),
                    'confirmation_date' => $member->confirmation_date?->toDateString(),
                    'marriage_date' => $member->marriage_date?->toDateString(),
                ];
            })
            ->values()
            ->all();
    }
}
