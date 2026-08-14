<?php

namespace Modules\Sacraments\Services;

use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Models\ChurchLeadership;

/**
 * Validates participant payloads against SacramentDefinition + source/affiliation invariants.
 */
class SacramentParticipantValidator
{
    public function __construct(
        protected SacramentDefinitionRegistry $definitions,
        protected ParticipantSnapshotBuilder $snapshots
    ) {}

    /**
     * @param  list<array<string, mixed>>  $participants
     * @return list<array<string, mixed>> normalized rows ready for insert (incl. snapshot_json)
     *
     * @throws SacramentBusinessRuleException
     */
    public function validateAndNormalize(SacramentType $type, array $participants, int $tenantId): array
    {
        $definition = $this->definitions->forTypeCode($type->code);
        if ($definition === null) {
            throw new SacramentBusinessRuleException(
                'invalid_sacrament_type',
                'No sacrament definition for this type code.'
            );
        }

        // Phase 10: gated types remain blocked; restricted types are authorized in SacramentService.
        if (! empty($definition['gated'])) {
            throw new SacramentBusinessRuleException(
                'sacrament_type_gated',
                'This sacrament type is not available for create in this phase.'
            );
        }

        $slotsByRole = [];
        foreach ($definition['participants'] as $slot) {
            $slotsByRole[$slot['role']] = $slot;
        }

        $counts = [];
        $normalized = [];
        $brideKey = null;
        $groomKey = null;

        foreach ($participants as $index => $raw) {
            $role = (string) ($raw['role'] ?? '');
            $source = (string) ($raw['source'] ?? '');

            if (! isset($slotsByRole[$role])) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_role',
                    "Role '{$role}' is not allowed for this sacrament.",
                    ['index' => $index, 'role' => $role]
                );
            }

            $slot = $slotsByRole[$role];
            if (! in_array($source, $slot['allowed_sources'], true)) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    "Source '{$source}' is not allowed for role '{$role}'.",
                    ['index' => $index, 'role' => $role, 'source' => $source]
                );
            }

            if ($source === SacramentParticipantSource::UNRESOLVED) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    'Unresolved participants cannot be created via the normal API.',
                    ['index' => $index]
                );
            }

            $row = $this->normalizeSourceFields($raw, $source, $tenantId, $index, $role);
            $row = $this->applyMarriageWitnessRules($type, $row, $index);

            if (($slot['affiliation_required_when_other'] ?? false)
                && ($row['affiliation_type'] ?? null) === 'other'
            ) {
                if (empty($row['affiliation_parish_name']) || empty($row['affiliation_diocese_name'])) {
                    throw new SacramentBusinessRuleException(
                        'affiliation_diocese_required',
                        'Parish name and diocese name are required when affiliation is other.',
                        ['index' => $index, 'role' => $role]
                    );
                }
            }

            $ministerLike = in_array($role, [
                SacramentParticipantRole::MINISTER,
                SacramentParticipantRole::CO_CONSECRATOR,
            ], true);
            if ($ministerLike
                && $source === SacramentParticipantSource::EXTERNAL
                && empty($row['external_minister_role'])
                && empty($row['external_title'])
            ) {
                throw new SacramentBusinessRuleException(
                    'external_minister_details_required',
                    'External minister requires a title or minister role.',
                    ['index' => $index]
                );
            }

            $row['snapshot_json'] = $this->snapshots->build($row, $source);
            unset($row['_resolved_member'], $row['_resolved_leadership'], $row['_resolved_person']);

            $counts[$role] = ($counts[$role] ?? 0) + 1;

            if ($role === SacramentParticipantRole::BRIDE) {
                $brideKey = $this->identityKey($row);
            }
            if ($role === SacramentParticipantRole::GROOM) {
                $groomKey = $this->identityKey($row);
            }

            $normalized[] = $row;
        }

        foreach ($slotsByRole as $role => $slot) {
            $count = $counts[$role] ?? 0;
            if ($count < (int) $slot['min']) {
                $code = $role === SacramentParticipantRole::MINISTER
                    ? 'minister_required'
                    : 'participant_cardinality';
                throw new SacramentBusinessRuleException(
                    $code,
                    "Role '{$role}' requires at least {$slot['min']} participant(s).",
                    ['role' => $role, 'min' => $slot['min'], 'count' => $count]
                );
            }
            if ($slot['max'] !== null && $count > (int) $slot['max']) {
                throw new SacramentBusinessRuleException(
                    'participant_cardinality',
                    "Role '{$role}' allows at most {$slot['max']} participant(s).",
                    ['role' => $role, 'max' => $slot['max'], 'count' => $count]
                );
            }
        }

        if ($brideKey !== null && $groomKey !== null && $brideKey === $groomKey) {
            throw new SacramentBusinessRuleException(
                'bride_equals_groom',
                'Bride and groom must be different people.'
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeSourceFields(
        array $raw,
        string $source,
        int $tenantId,
        int $index,
        string $role
    ): array {
        $row = [
            'role' => $role,
            'source' => $source,
            'sort_order' => (int) ($raw['sort_order'] ?? 0),
            'family_member_id' => null,
            'person_id' => null,
            'church_leadership_id' => null,
            'affiliation_type' => $raw['affiliation_type'] ?? null,
            'affiliation_parish_name' => $raw['affiliation_parish_name'] ?? null,
            'affiliation_parish_address' => $raw['affiliation_parish_address'] ?? null,
            'affiliation_diocese_name' => $raw['affiliation_diocese_name'] ?? null,
            'affiliation_diocese_region' => $raw['affiliation_diocese_region'] ?? null,
            'affiliation_diocese_country' => $raw['affiliation_diocese_country'] ?? null,
            'external_full_name' => $raw['external_full_name'] ?? null,
            'external_date_of_birth' => $raw['external_date_of_birth'] ?? null,
            'external_gender' => $raw['external_gender'] ?? null,
            'external_address' => $raw['external_address'] ?? null,
            'external_contact_number' => $raw['external_contact_number'] ?? null,
            'external_title' => $raw['external_title'] ?? null,
            'external_minister_role' => $raw['external_minister_role'] ?? null,
        ];

        if ($source === SacramentParticipantSource::MEMBER) {
            $memberId = $raw['family_member_id'] ?? null;
            if (! $memberId) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    'family_member_id is required when source is member.',
                    ['index' => $index, 'role' => $role]
                );
            }

            $member = FamilyMember::query()
                ->with('person')
                ->where('id', $memberId)
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if (! $member) {
                throw new SacramentBusinessRuleException(
                    'cross_tenant_member',
                    'Family member not found in this parish.',
                    ['index' => $index, 'family_member_id' => $memberId]
                );
            }

            $row['family_member_id'] = $member->id;
            $row['person_id'] = $member->person_id;
            $row['_resolved_member'] = $member;
            $row['_resolved_person'] = $member->person;
        }

        if ($source === SacramentParticipantSource::PERSON) {
            $personId = $raw['person_id'] ?? null;
            if (! $personId) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    'person_id is required when source is person.',
                    ['index' => $index, 'role' => $role]
                );
            }

            $person = Person::query()
                ->forTenant($tenantId)
                ->where('id', $personId)
                ->first();

            if (! $person) {
                throw new SacramentBusinessRuleException(
                    'cross_tenant_member',
                    'Person not found in this parish.',
                    ['index' => $index, 'person_id' => $personId]
                );
            }

            $row['person_id'] = $person->id;
            $row['family_member_id'] = null;
            $row['_resolved_person'] = $person;
        }

        if ($source === SacramentParticipantSource::INTERNAL_LEADERSHIP) {
            $leadershipId = $raw['church_leadership_id'] ?? null;
            if (! $leadershipId) {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    'church_leadership_id is required when source is internal_leadership.',
                    ['index' => $index, 'role' => $role]
                );
            }

            $leader = ChurchLeadership::query()
                ->where('id', $leadershipId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (! $leader) {
                throw new SacramentBusinessRuleException(
                    'cross_tenant_member',
                    'Church leadership record not found in this parish.',
                    ['index' => $index, 'church_leadership_id' => $leadershipId]
                );
            }

            $row['church_leadership_id'] = $leader->id;
            $row['_resolved_leadership'] = $leader;
        }

        if ($source === SacramentParticipantSource::EXTERNAL) {
            $name = trim((string) ($raw['external_full_name'] ?? ''));
            if ($name === '') {
                throw new SacramentBusinessRuleException(
                    'invalid_participant_source',
                    'external_full_name is required when source is external.',
                    ['index' => $index, 'role' => $role]
                );
            }
            $row['external_full_name'] = $name;
        }

        return $row;
    }

    /**
     * Marriage witnesses are sacrament-local external people (no Person / FamilyMember / Family).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function applyMarriageWitnessRules(SacramentType $type, array $row, int $index): array
    {
        if (! SacramentTypeCode::isMatrimony($type->code)
            || ($row['role'] ?? '') !== SacramentParticipantRole::WITNESS
        ) {
            return $row;
        }

        if (($row['source'] ?? '') !== SacramentParticipantSource::EXTERNAL) {
            throw new SacramentBusinessRuleException(
                'invalid_participant_source',
                'A marriage witness must be recorded as an external person, not a parish Person or family member.',
                ['index' => $index, 'role' => SacramentParticipantRole::WITNESS]
            );
        }

        $row['family_member_id'] = null;
        $row['person_id'] = null;
        $row['church_leadership_id'] = null;
        $row['external_date_of_birth'] = null;
        unset($row['_resolved_member'], $row['_resolved_person'], $row['_resolved_leadership']);

        $required = [
            'external_full_name' => 'Witness full name is required.',
            'external_address' => 'Witness address is required.',
            'external_gender' => 'Witness gender is required.',
            'external_contact_number' => 'Witness contact number is required.',
        ];

        foreach ($required as $field => $message) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                throw new SacramentBusinessRuleException(
                    'marriage_witness_details_required',
                    $message,
                    ['index' => $index, 'role' => SacramentParticipantRole::WITNESS, 'field' => $field]
                );
            }
        }

        $row['external_address'] = trim((string) $row['external_address']);
        $row['external_contact_number'] = trim((string) $row['external_contact_number']);
        $row['external_gender'] = trim((string) $row['external_gender']);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function identityKey(array $row): string
    {
        if (! empty($row['family_member_id'])) {
            return 'm:'.$row['family_member_id'];
        }
        if (! empty($row['person_id'])) {
            return 'p:'.$row['person_id'];
        }

        $name = strtolower(trim((string) ($row['external_full_name'] ?? '')));
        $dob = (string) ($row['external_date_of_birth'] ?? '');

        return 'e:'.$name.'|'.$dob;
    }
}
