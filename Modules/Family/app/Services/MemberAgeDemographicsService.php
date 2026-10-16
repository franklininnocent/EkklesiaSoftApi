<?php

namespace Modules\Family\app\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\FamilyQueryFilters;

/**
 * Parish-wide member age and gender demographics (all households in tenant).
 * Age bands reuse {@see BccAgeBands} — the canonical band definitions.
 */
class MemberAgeDemographicsService
{
    /**
     * @return array{total: int, gender: array<string, array{count: int, percent: float}>, age_groups: array<string, array{label: string, min: int|null, max: int|null, count: int, percent: float}>}
     */
    public function forTenant(int $tenantId): array
    {
        return $this->summarize($this->memberBaseQuery($tenantId), false);
    }

    /**
     * Age-band payload only (executive people chart). Skips gender aggregation.
     *
     * @return array<string, array{label: string, min: int|null, max: int|null, count: int, percent: float}>
     */
    public function ageGroupsForTenant(int $tenantId): array
    {
        return $this->summarize($this->memberBaseQuery($tenantId), false, false)['age_groups'];
    }

    /**
     * Family command-center demographics: age percents among members with DOB,
     * gender percents among members with recorded gender.
     *
     * @return array{
     *     total: int,
     *     with_dob: int,
     *     without_dob: int,
     *     with_gender: int,
     *     without_gender: int,
     *     under_18: int,
     *     gender: array<string, array{count: int, percent: float}>,
     *     age_groups: array<string, array{label: string, min: int|null, max: int|null, count: int, percent: float}>
     * }
     */
    public function forFamilyDashboard(int $tenantId, ?string $bccId, ?string $familyStatus): array
    {
        return $this->summarize($this->memberBaseQuery($tenantId, $bccId, $familyStatus), true);
    }

    /**
     * @param  Builder<FamilyMember>  $memberBase
     * @return array<string, mixed>
     */
    private function summarize(Builder $memberBase, bool $recordedDenominators, bool $includeGender = true): array
    {
        $total = (clone $memberBase)->count();

        $genderCounts = collect();
        if ($includeGender) {
            $genderCounts = (clone $memberBase)
                ->select([
                    DB::raw("COALESCE(NULLIF(LOWER(TRIM(family_members.gender)), ''), 'unknown') as gender_key"),
                    DB::raw('COUNT(*) as aggregate'),
                ])
                ->groupBy(DB::raw("COALESCE(NULLIF(LOWER(TRIM(family_members.gender)), ''), 'unknown')"))
                ->pluck('aggregate', 'gender_key');
        }

        $ageYears = BccAgeBands::ageYearsSql();
        $ageSql = BccAgeBands::sqlCase(
            "CASE WHEN family_members.date_of_birth IS NULL THEN NULL ELSE {$ageYears} END"
        );

        $ageCounts = (clone $memberBase)
            ->select([
                DB::raw("{$ageSql} as age_band"),
                DB::raw('COUNT(*) as aggregate'),
            ])
            ->groupBy(DB::raw($ageSql))
            ->pluck('aggregate', 'age_band');

        $withGender = $total - (int) ($genderCounts['unknown'] ?? 0);
        $genderDenom = $recordedDenominators ? $withGender : $total;
        $gender = [];
        foreach (['male', 'female', 'other', 'unknown'] as $key) {
            $count = (int) ($genderCounts[$key] ?? 0);
            $denom = ($recordedDenominators && $key === 'unknown') ? 0 : $genderDenom;
            $gender[$key] = [
                'count' => $count,
                'percent' => $denom > 0 ? round(($count / $denom) * 100, 1) : 0.0,
            ];
        }

        $unknownAge = (int) ($ageCounts[BccAgeBands::UNKNOWN] ?? 0);
        $withDob = $total - $unknownAge;
        $ageDenom = $recordedDenominators ? $withDob : $total;
        $ageGroups = [];
        $under18 = 0;
        foreach (BccAgeBands::definitions() as $band) {
            $count = (int) ($ageCounts[$band['key']] ?? 0);
            $isUnknown = $band['key'] === BccAgeBands::UNKNOWN;
            $denom = ($recordedDenominators && $isUnknown) ? 0 : $ageDenom;
            $ageGroups[$band['key']] = [
                'label' => $band['label'],
                'min' => $band['min'],
                'max' => $band['max'],
                'count' => $count,
                'percent' => $denom > 0 ? round(($count / $denom) * 100, 1) : 0.0,
            ];
            if (in_array($band['key'], [BccAgeBands::BABIES, BccAgeBands::CHILDREN, BccAgeBands::TEENAGERS], true)) {
                $under18 += $count;
            }
        }

        return [
            'total' => $total,
            'with_dob' => $withDob,
            'without_dob' => $unknownAge,
            'with_gender' => $withGender,
            'without_gender' => (int) ($genderCounts['unknown'] ?? 0),
            'under_18' => $under18,
            'gender' => $gender,
            'age_groups' => $ageGroups,
        ];
    }

    /**
     * @return Builder<FamilyMember>
     */
    public function memberBaseQuery(int $tenantId, ?string $bccId = null, ?string $familyStatus = null): Builder
    {
        return FamilyMember::query()
            ->where('family_members.tenant_id', (string) $tenantId)
            ->whereIn('family_members.family_id', function ($query) use ($tenantId, $bccId, $familyStatus): void {
                $query->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
                FamilyQueryFilters::applyBcc($query, $bccId);
                FamilyQueryFilters::applyFamilyStatus($query, $familyStatus);
            })
            ->whereNull('family_members.deleted_at');
    }
}
