<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Collection;
use Modules\Family\Models\FamilyMember;

/**
 * Resolve canonical father/mother display names from family membership relationships.
 */
class FamilyMemberParentNameResolver
{
    /**
     * @param  iterable<FamilyMember>  $members
     */
    public function attachToMembers(iterable $members): void
    {
        $memberList = $members instanceof Collection ? $members : collect($members);
        if ($memberList->isEmpty()) {
            return;
        }

        $familyIds = $memberList->pluck('family_id')->filter()->unique()->values();
        if ($familyIds->isEmpty()) {
            return;
        }

        $familyMembersByFamily = FamilyMember::query()
            ->whereIn('family_id', $familyIds)
            ->whereNull('deleted_at')
            ->get(['id', 'family_id', 'first_name', 'middle_name', 'last_name', 'relationship_to_head', 'gender', 'person_id'])
            ->groupBy('family_id');

        foreach ($memberList as $member) {
            $familyMembers = $familyMembersByFamily->get($member->family_id, collect());
            $resolved = $this->resolveForMember($member, $familyMembers);
            $member->setAttribute('father_name', $resolved['father_name']);
            $member->setAttribute('mother_name', $resolved['mother_name']);
        }
    }

    /**
     * @param  Collection<int, FamilyMember>  $familyMembers
     * @return array{father_name: ?string, mother_name: ?string}
     */
    public function resolveForMember(FamilyMember $member, Collection $familyMembers): array
    {
        $others = $familyMembers->where('id', '!=', $member->id);

        $father = $others->first(
            fn (FamilyMember $row) => $row->relationship_to_head === 'father'
        );

        if ($father === null && in_array($member->relationship_to_head, ['son', 'daughter'], true)) {
            $father = $others->first(
                fn (FamilyMember $row) => $row->relationship_to_head === 'self' && $row->gender === 'male'
            );
        }

        $mother = $others->first(
            fn (FamilyMember $row) => $row->relationship_to_head === 'mother'
        );

        if ($mother === null && in_array($member->relationship_to_head, ['son', 'daughter'], true)) {
            $mother = $others->first(
                fn (FamilyMember $row) => $row->relationship_to_head === 'spouse' && $row->gender === 'female'
            );
        }

        $fatherName = $father ? $this->fullName($father) : null;
        $motherName = $mother ? $this->fullName($mother) : null;

        if ($fatherName === null && $member->relationLoaded('person') && $member->person?->father_name) {
            $fatherName = trim((string) $member->person->father_name) ?: null;
        }

        if ($motherName === null && $member->relationLoaded('person') && $member->person?->mother_name) {
            $motherName = trim((string) $member->person->mother_name) ?: null;
        }

        return [
            'father_name' => $fatherName,
            'mother_name' => $motherName,
        ];
    }

    private function fullName(FamilyMember $member): string
    {
        return trim(implode(' ', array_filter([
            $member->first_name,
            $member->middle_name,
            $member->last_name,
        ])));
    }
}
