<?php

namespace Modules\Sacraments\Services;

use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Support\BaptismalStatus;
use Modules\Sacraments\Support\CanonicalDelegationStatus;
use Modules\Sacraments\Support\EcclesialAffiliation;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\LeadershipAssignment;

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
            'schema_version' => 2,
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
                return array_merge($base, $this->personSnapshotFields($person, $member, $participant), [
                    'family_member_id' => $member->id,
                    'person_id' => $person?->id ?? $member->person_id,
                ], $this->affiliationSnapshot($participant), $this->canonicalSnapshot($participant));
            }
        }

        if ($source === SacramentParticipantSource::PERSON) {
            /** @var Person|null $person */
            $person = $participant['_resolved_person'] ?? null;
            if ($person) {
                return array_merge($base, $this->personSnapshotFields($person, null, $participant), [
                    'person_id' => $person->id,
                ], $this->affiliationSnapshot($participant), $this->canonicalSnapshot($participant));
            }
        }

        if ($source === SacramentParticipantSource::INTERNAL_LEADERSHIP) {
            /** @var LeadershipAssignment|null $assignment */
            $assignment = $participant['_resolved_leadership_assignment'] ?? null;
            if ($assignment) {
                $person = $assignment->person;
                $roleTitle = $assignment->role?->title;

                return array_merge($base, [
                    'full_name' => $person?->full_name_display ?? trim(($person?->first_name ?? '').' '.($person?->last_name ?? '')),
                    'title' => null,
                    'minister_role' => $roleTitle,
                    'leadership_assignment_id' => $assignment->id,
                    'person_id' => $assignment->person_id,
                ], $this->affiliationSnapshot($participant), $this->canonicalSnapshot($participant));
            }

            /** @var ChurchLeadership|null $leader */
            $leader = $participant['_resolved_leadership'] ?? null;
            if ($leader) {
                return array_merge($base, [
                    'full_name' => $leader->full_name,
                    'title' => $leader->title,
                    'minister_role' => $leader->role,
                    'church_leadership_id' => $leader->id,
                ], $this->affiliationSnapshot($participant), $this->canonicalSnapshot($participant));
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
            'father_name' => $this->optionalText($participant['father_name'] ?? null),
            'mother_name' => $this->optionalText($participant['mother_name'] ?? null),
        ], $this->affiliationSnapshot($participant), $this->canonicalSnapshot($participant));
    }

    /**
     * Historical identity captured at register/correct time (ADR-02 / ADR-24).
     *
     * @param  array<string, mixed>  $participant
     * @return array<string, mixed>
     */
    private function personSnapshotFields(?Person $person, ?FamilyMember $member = null, array $participant = []): array
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
            'father_name' => $this->optionalText($participant['father_name'] ?? null) ?? $person?->father_name,
            'mother_name' => $this->optionalText($participant['mother_name'] ?? null) ?? $person?->mother_name,
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

    /**
     * @param  array<string, mixed>  $participant
     * @return array<string, mixed>
     */
    private function canonicalSnapshot(array $participant): array
    {
        $code = $participant['ecclesial_affiliation_code'] ?? null;
        $label = $participant['ecclesial_affiliation_label'] ?? null;
        $baptismal = $participant['baptismal_status'] ?? null;
        $delegation = $participant['canonical_delegation_status'] ?? null;

        return [
            'baptismal_status' => BaptismalStatus::isValid(is_string($baptismal) ? $baptismal : null) ? $baptismal : null,
            'baptismal_status_label' => BaptismalStatus::label(is_string($baptismal) ? $baptismal : null),
            'ecclesial_affiliation_code' => EcclesialAffiliation::isValid(is_string($code) ? $code : null) ? $code : null,
            'ecclesial_affiliation_label' => EcclesialAffiliation::label(
                is_string($code) ? $code : null,
                is_string($label) ? $label : null
            ),
            'canonical_delegation_status' => CanonicalDelegationStatus::isValid(is_string($delegation) ? $delegation : null)
                ? $delegation
                : null,
            'canonical_delegation_status_label' => CanonicalDelegationStatus::label(
                is_string($delegation) ? $delegation : null
            ),
        ];
    }

    private function optionalText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
