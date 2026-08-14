<?php

namespace Modules\Sacraments\Services;

use Modules\Family\app\Services\FamilyService;
use Modules\Family\app\Services\PersonMatchService;
use Modules\Family\app\Services\PersonService;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Resolves Baptism/Eucharist recipient Person + optional family association (ADR-24).
 * Never mutates an existing Person.
 */
class SacramentRecipientResolver
{
    public function __construct(
        protected PersonService $personService,
        protected PersonMatchService $matchService,
        protected FamilyService $familyService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data, SacramentType $type, int $tenantId, ?int $userId): array
    {
        $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);
        $association = $data['family_association'] ?? null;

        if ($association !== null && ! in_array($code, [SacramentTypeCode::BAPTISM, SacramentTypeCode::EUCHARIST], true)) {
            throw new SacramentBusinessRuleException(
                'family_association_not_supported',
                'Family association is only supported for Baptism and Eucharist.'
            );
        }

        if ($association !== null) {
            $data = match ($association) {
                'existing' => $this->fromExistingFamily($data, $tenantId),
                'none' => $this->fromNoFamily($data, $tenantId, $userId),
                'new' => $this->fromNewFamily($data, $tenantId, $userId),
                default => throw new SacramentBusinessRuleException(
                    'invalid_family_association',
                    'Family association must be none, existing, or new.'
                ),
            };
        } else {
            $data = $this->stampPersonFromRecipientParticipant($data, $tenantId);
        }

        if (in_array($code, [SacramentTypeCode::BAPTISM, SacramentTypeCode::EUCHARIST], true) && $association !== null) {
            if (empty($data['person_id'])) {
                throw new SacramentBusinessRuleException(
                    'recipient_person_required',
                    'Baptism and Eucharist must belong to a parish Person.'
                );
            }
        }

        $this->assertRecipientIdentity($data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromExistingFamily(array $data, int $tenantId): array
    {
        $memberId = $data['family_member_id'] ?? $this->recipientMemberId($data);
        if (! $memberId) {
            throw new SacramentBusinessRuleException(
                'family_member_required',
                'Select the recipient from the existing family.'
            );
        }

        $member = FamilyMember::query()
            ->where('id', $memberId)
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();

        if (! $member || ! $member->person_id) {
            throw new SacramentBusinessRuleException(
                'cross_tenant_member',
                'Family member not found in this parish.'
            );
        }

        if (! empty($data['family_id']) && (string) $data['family_id'] !== (string) $member->family_id) {
            throw new SacramentBusinessRuleException(
                'family_member_mismatch',
                'The selected member does not belong to the selected family.'
            );
        }

        $data['person_id'] = $member->person_id;
        $data['family_id'] = $member->family_id;
        $data['participants'] = $this->ensureRecipientParticipant(
            $data['participants'] ?? [],
            SacramentParticipantSource::MEMBER,
            $member->person_id,
            $member->id
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromNoFamily(array $data, int $tenantId, ?int $userId): array
    {
        $person = $this->resolveOrCreatePerson($data, $tenantId, $userId);
        $data['person_id'] = $person->id;
        $data['family_id'] = null;
        $data['family_member_id'] = null;
        $data['participants'] = $this->ensureRecipientParticipant(
            $data['participants'] ?? [],
            SacramentParticipantSource::PERSON,
            $person->id,
            null
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromNewFamily(array $data, int $tenantId, ?int $userId): array
    {
        $familyInput = $data['family'] ?? null;
        if (! is_array($familyInput) || trim((string) ($familyInput['family_name'] ?? '')) === '') {
            throw new SacramentBusinessRuleException(
                'family_required',
                'New family details are required.'
            );
        }

        $person = $this->resolveOrCreatePerson($data, $tenantId, $userId);
        $created = $this->familyService->createFamilyWithPerson(
            $familyInput,
            $person,
            [
                'relationship_to_head' => $data['relationship_to_head'] ?? 'self',
            ],
            $tenantId,
            (int) $userId
        );

        $data['person_id'] = $created['person']->id;
        $data['family_id'] = $created['family']->id;
        $data['family_member_id'] = $created['member']->id;
        $data['bcc_id'] = $data['bcc_id'] ?? $created['family']->bcc_id;
        $data['participants'] = $this->ensureRecipientParticipant(
            $data['participants'] ?? [],
            SacramentParticipantSource::MEMBER,
            $created['person']->id,
            $created['member']->id
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveOrCreatePerson(array $data, int $tenantId, ?int $userId): Person
    {
        if (! empty($data['use_person_id'])) {
            return $this->personService->resolve((string) $data['use_person_id'], $tenantId);
        }

        if (! empty($data['person_id']) && empty($data['person'])) {
            return $this->personService->resolve((string) $data['person_id'], $tenantId);
        }

        $personInput = $data['person'] ?? $this->personInputFromLegacy($data);
        $this->assertPersonInput($personInput);

        $acknowledge = (bool) ($data['acknowledge_person_match'] ?? false);
        $forceCreate = $acknowledge && empty($data['use_person_id']);

        if (! $acknowledge && ! $forceCreate) {
            $matches = $this->matchService->findPossibleMatches($tenantId, $personInput);
            if ($matches !== []) {
                throw new SacramentBusinessRuleException(
                    'possible_person_match',
                    'A possible existing person was found. Choose Use Existing Person or Create New Person.',
                    ['matches' => $matches],
                    409
                );
            }
        }

        return $this->personService->create($personInput, $tenantId, $userId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stampPersonFromRecipientParticipant(array $data, int $tenantId): array
    {
        $participants = $data['participants'] ?? [];
        if (! is_array($participants)) {
            return $data;
        }

        foreach ($participants as $index => $row) {
            if (($row['role'] ?? '') !== SacramentParticipantRole::RECIPIENT) {
                continue;
            }
            if (($row['source'] ?? '') === SacramentParticipantSource::MEMBER && ! empty($row['family_member_id'])) {
                $member = FamilyMember::query()
                    ->where('id', $row['family_member_id'])
                    ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                    ->first();
                if ($member?->person_id) {
                    $data['person_id'] = $member->person_id;
                    $participants[$index]['person_id'] = $member->person_id;
                }
            }
            if (($row['source'] ?? '') === SacramentParticipantSource::PERSON && ! empty($row['person_id'])) {
                $person = $this->personService->resolve((string) $row['person_id'], $tenantId);
                $data['person_id'] = $person->id;
                $participants[$index]['person_id'] = $person->id;
            }
        }

        $data['participants'] = $participants;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertRecipientIdentity(array $data): void
    {
        $sacramentPersonId = $data['person_id'] ?? null;
        if (! $sacramentPersonId) {
            return;
        }

        foreach ($data['participants'] ?? [] as $row) {
            if (($row['role'] ?? '') !== SacramentParticipantRole::RECIPIENT) {
                continue;
            }
            $participantPersonId = $row['person_id'] ?? null;
            if ($participantPersonId && (string) $participantPersonId !== (string) $sacramentPersonId) {
                throw new SacramentBusinessRuleException(
                    'recipient_identity_mismatch',
                    'Sacrament recipient person_id must match the recipient participant person_id.',
                    [
                        'sacrament_person_id' => $sacramentPersonId,
                        'participant_person_id' => $participantPersonId,
                    ]
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @return list<array<string, mixed>>
     */
    private function ensureRecipientParticipant(
        array $participants,
        string $source,
        string $personId,
        ?string $familyMemberId
    ): array {
        $found = false;
        foreach ($participants as $i => $row) {
            if (($row['role'] ?? '') !== SacramentParticipantRole::RECIPIENT) {
                continue;
            }
            $found = true;
            $participants[$i]['source'] = $source;
            $participants[$i]['person_id'] = $personId;
            $participants[$i]['family_member_id'] = $familyMemberId;
            unset($participants[$i]['external_full_name']);
        }

        if (! $found) {
            array_unshift($participants, [
                'role' => SacramentParticipantRole::RECIPIENT,
                'source' => $source,
                'person_id' => $personId,
                'family_member_id' => $familyMemberId,
                'sort_order' => 0,
            ]);
        }

        return $participants;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recipientMemberId(array $data): ?string
    {
        foreach ($data['participants'] ?? [] as $row) {
            if (($row['role'] ?? '') === SacramentParticipantRole::RECIPIENT
                && ! empty($row['family_member_id'])
            ) {
                return (string) $row['family_member_id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personInputFromLegacy(array $data): array
    {
        $name = trim((string) ($data['recipient_name'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? array_pop($parts) : '';
        $middle = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;

        return [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last !== '' ? $last : $first,
            'date_of_birth' => $data['recipient_birth_date'] ?? null,
            'place_of_birth' => $data['recipient_birth_place'] ?? null,
            'gender' => $data['recipient_gender'] ?? null,
            'father_name' => $data['father_name'] ?? null,
            'mother_name' => $data['mother_name'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertPersonInput(array $input): void
    {
        if (trim((string) ($input['first_name'] ?? '')) === '' || trim((string) ($input['last_name'] ?? '')) === '') {
            throw new SacramentBusinessRuleException(
                'person_name_required',
                'First name and last name are required to create a Person.'
            );
        }
    }
}
