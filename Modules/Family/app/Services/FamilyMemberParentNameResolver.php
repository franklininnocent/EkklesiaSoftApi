<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Collection;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;

/**
 * Resolve canonical father/mother display names from Person parents, then household heuristics.
 */
class FamilyMemberParentNameResolver
{
    public function __construct(
        protected PersonParentRelationshipService $parentRelationshipService
    ) {}

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
            $member->setAttribute('display_father_name', $resolved['display_father_name']);
            $member->setAttribute('display_mother_name', $resolved['display_mother_name']);
            $member->setAttribute('father_person_id', $resolved['father_person_id']);
            $member->setAttribute('mother_person_id', $resolved['mother_person_id']);
        }
    }

    /**
     * @param  Collection<int, FamilyMember>  $familyMembers
     * @return array{
     *     father_name: ?string,
     *     mother_name: ?string,
     *     display_father_name: ?string,
     *     display_mother_name: ?string,
     *     father_person_id: ?string,
     *     mother_person_id: ?string
     * }
     */
    public function resolveForMember(FamilyMember $member, Collection $familyMembers): array
    {
        $person = $member->relationLoaded('person') ? $member->person : null;

        $explicitFather = $person ? $this->explicitParentDisplay($person, 'father') : null;
        $explicitMother = $person ? $this->explicitParentDisplay($person, 'mother') : null;

        $heuristic = $this->resolveHouseholdHeuristic($member, $familyMembers);

        $fatherName = $explicitFather['display'] ?? $heuristic['father_name'];
        $motherName = $explicitMother['display'] ?? $heuristic['mother_name'];

        return [
            'father_name' => $fatherName,
            'mother_name' => $motherName,
            'display_father_name' => $fatherName,
            'display_mother_name' => $motherName,
            'father_person_id' => $explicitFather['person_id'] ?? ($person?->father_person_id),
            'mother_person_id' => $explicitMother['person_id'] ?? ($person?->mother_person_id),
        ];
    }

    /**
     * @return array{display: ?string, person_id: ?string, snapshot: ?string}
     */
    private function explicitParentDisplay(Person $person, string $side): array
    {
        $hasExplicit = $side === 'father'
            ? ($person->father_person_id || filled($person->father_name))
            : ($person->mother_person_id || filled($person->mother_name));

        if (! $hasExplicit) {
            return ['display' => null, 'person_id' => null, 'snapshot' => null];
        }

        $display = $side === 'father'
            ? $this->parentRelationshipService->displayFatherName($person)
            : $this->parentRelationshipService->displayMotherName($person);

        return [
            'display' => $display,
            'person_id' => $side === 'father' ? $person->father_person_id : $person->mother_person_id,
            'snapshot' => $side === 'father' ? $person->father_name : $person->mother_name,
        ];
    }

    /**
     * @param  Collection<int, FamilyMember>  $familyMembers
     * @return array{father_name: ?string, mother_name: ?string}
     */
    private function resolveHouseholdHeuristic(FamilyMember $member, Collection $familyMembers): array
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
