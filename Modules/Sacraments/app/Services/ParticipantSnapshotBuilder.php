<?php

namespace Modules\Sacraments\Services;

use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Tenants\Models\ChurchLeadership;

/**
 * Server-built participant snapshots (ADR-02). Clients must not supply authoritative snapshots.
 */
class ParticipantSnapshotBuilder
{
    /**
     * @param  array<string, mixed>  $participant
     * @return array<string, mixed>
     */
    public function build(array $participant, string $source): array
    {
        $base = [
            'schema_version' => 1,
            'role' => $participant['role'] ?? null,
            'source' => $source,
            'captured_at' => now()->toIso8601String(),
        ];

        if ($source === SacramentParticipantSource::MEMBER) {
            /** @var FamilyMember|null $member */
            $member = $participant['_resolved_member'] ?? null;
            /** @var Person|null $person */
            $person = $participant['_resolved_person'] ?? $member?->person;
            if ($member) {
                return array_merge($base, $this->personSnapshotFields($person, $member), [
                    'family_member_id' => $member->id,
                    'person_id' => $person?->id ?? $member->person_id,
                ], $this->affiliationSnapshot($participant));
            }
        }

        if ($source === SacramentParticipantSource::PERSON) {
            /** @var Person|null $person */
            $person = $participant['_resolved_person'] ?? null;
            if ($person) {
                return array_merge($base, $this->personSnapshotFields($person), [
                    'person_id' => $person->id,
                ], $this->affiliationSnapshot($participant));
            }
        }

        if ($source === SacramentParticipantSource::INTERNAL_LEADERSHIP) {
            /** @var ChurchLeadership|null $leader */
            $leader = $participant['_resolved_leadership'] ?? null;
            if ($leader) {
                return array_merge($base, [
                    'full_name' => $leader->full_name,
                    'title' => $leader->title,
                    'minister_role' => $leader->role,
                    'church_leadership_id' => $leader->id,
                ], $this->affiliationSnapshot($participant));
            }
        }

        // external / unresolved / fallback
        return array_merge($base, [
            'full_name' => $participant['external_full_name'] ?? null,
            'date_of_birth' => $participant['external_date_of_birth'] ?? null,
            'gender' => $participant['external_gender'] ?? null,
            'address' => $participant['external_address'] ?? null,
            'contact_number' => $participant['external_contact_number'] ?? null,
            'title' => $participant['external_title'] ?? null,
            'minister_role' => $participant['external_minister_role'] ?? null,
        ], $this->affiliationSnapshot($participant));
    }

    /**
     * Historical identity captured at register/correct time (ADR-02 / ADR-24).
     *
     * @return array<string, mixed>
     */
    private function personSnapshotFields(?Person $person, ?FamilyMember $member = null): array
    {
        $first = $person?->first_name ?? $member?->first_name;
        $middle = $person?->middle_name ?? $member?->middle_name;
        $last = $person?->last_name ?? $member?->last_name;

        return [
            'full_name' => $person?->full_name_display ?? trim(implode(' ', array_filter([$first, $middle, $last]))),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'date_of_birth' => optional($person?->date_of_birth ?? $member?->date_of_birth)?->format('Y-m-d'),
            'place_of_birth' => $person?->place_of_birth,
            'gender' => $person?->gender ?? $member?->gender,
            'father_name' => $person?->father_name,
            'mother_name' => $person?->mother_name,
        ];
    }

    /**
     * @param  array<string, mixed>  $participant
     * @return array<string, mixed>
     */
    private function affiliationSnapshot(array $participant): array
    {
        return [
            'affiliation' => [
                'type' => $participant['affiliation_type'] ?? null,
                'parish_name' => $participant['affiliation_parish_name'] ?? null,
                'parish_address' => $participant['affiliation_parish_address'] ?? null,
                'diocese_name' => $participant['affiliation_diocese_name'] ?? null,
                'diocese_region' => $participant['affiliation_diocese_region'] ?? null,
                'diocese_country' => $participant['affiliation_diocese_country'] ?? null,
            ],
        ];
    }
}
