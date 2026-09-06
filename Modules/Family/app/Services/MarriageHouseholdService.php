<?php

namespace Modules\Family\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\Family\app\Exceptions\HouseholdTransitionException;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Events\MemberJoinedHouseholdViaMarriage;
use Modules\Family\Events\NewFamilyEstablishedViaMarriage;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\HouseholdTransition;
use Modules\Family\Models\Person;

class MarriageHouseholdService
{
    public function __construct(
        protected FamilyRepository $familyRepository,
        protected HouseholdSuccessionService $successionService,
        protected HouseholdTransitionHistoryRecorder $historyRecorder,
        protected BCCMembershipTransitionService $bccTransitionService,
        protected PersonMatchService $personMatchService,
        protected PersonService $personService,
        protected FamilyAuditService $familyAuditService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function processTransition(array $data, int|string $tenantId, int|string $userId): array
    {
        $transitionId = (string) $data['transition_id'];
        $effectiveDate = (string) $data['effective_date'];

        if ($effectiveDate > now()->toDateString()) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::INVALID_EFFECTIVE_DATE,
                'Effective date cannot be in the future.'
            );
        }

        $existing = HouseholdTransition::query()
            ->where('tenant_id', $tenantId)
            ->where('transition_id', $transitionId)
            ->where('status', HouseholdTransition::STATUS_COMPLETED)
            ->first();

        if ($existing !== null) {
            return array_merge($existing->result_summary ?? [], [
                'code' => HouseholdTransitionException::TRANSITION_ALREADY_COMPLETED,
                'idempotent_replay' => true,
            ]);
        }

        return match ((string) $data['outcome']) {
            'new_household' => $this->processNewHousehold($data, $tenantId, $userId, $transitionId, $effectiveDate),
            'join_existing' => $this->processJoinExisting($data, $tenantId, $userId, $transitionId, $effectiveDate),
            default => throw HouseholdTransitionException::validation(
                HouseholdTransitionException::UNAUTHORIZED_TRANSITION,
                'Invalid marriage transition outcome.'
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processNewHousehold(
        array $data,
        int|string $tenantId,
        int|string $userId,
        string $transitionId,
        string $effectiveDate,
    ): array {
        $bride = $this->resolveParticipant($data, 'bride', $tenantId, $userId);
        $groom = $this->resolveParticipant($data, 'groom', $tenantId, $userId);
        $this->assertDistinctParticipants($bride, $groom);

        if ($bride['member'] !== null && $groom['member'] !== null
            && $bride['member']->family_id === $groom['member']->family_id) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::SAME_FAMILY_MARRIAGE_NOT_ALLOWED,
                'Both participants cannot already belong to the same family.'
            );
        }

        $targetBccId = (string) ($data['new_household']['bcc_id'] ?? '');
        $bcc = BCC::query()->forTenant((string) $tenantId)->find($targetBccId);
        if ($bcc === null || $bcc->status !== 'active') {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::TARGET_BCC_NOT_FOUND,
                'Target BCC not found or inactive.'
            );
        }

        $newHeadKey = (string) $data['new_household_head_member_id'];
        if (! in_array($newHeadKey, [$bride['key'], $groom['key']], true)) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::INVALID_SUCCESSION_MEMBER,
                'New household head must be one of the marriage participants.'
            );
        }

        $originSuccessions = $data['origin_successions'] ?? [];

        return DB::transaction(function () use (
            $data,
            $tenantId,
            $userId,
            $transitionId,
            $effectiveDate,
            $bride,
            $groom,
            $targetBccId,
            $newHeadKey,
            $originSuccessions
        ): array {
            $newFamilyData = array_merge($data['new_household'] ?? [], [
                'tenant_id' => $tenantId,
                'status' => 'active',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            unset($newFamilyData['bcc_id']);

            $newFamily = $this->familyRepository->create($newFamilyData);
            $originFamilyIds = [];
            $departingMemberIds = [];
            $placedMemberIds = [];

            foreach ([$bride, $groom] as $participant) {
                $role = $participant['key'] === $newHeadKey ? 'self' : 'spouse';
                $fromFamilyId = $participant['member'] !== null
                    ? (string) $participant['member']->family_id
                    : null;
                $previousRole = $participant['member'] !== null
                    ? (string) $participant['member']->relationship_to_head
                    : null;

                $member = $this->placeParticipantInFamily(
                    $participant,
                    $newFamily,
                    $role,
                    $tenantId,
                    $userId,
                );

                if ($participant['member'] !== null) {
                    $originFamilyIds[] = $fromFamilyId;
                    $departingMemberIds[] = (string) $member->id;

                    $this->historyRecorder->record(
                        $tenantId,
                        $transitionId,
                        (string) $member->id,
                        $fromFamilyId,
                        $newFamily->id,
                        $previousRole,
                        $role,
                        'MARRIAGE',
                        $effectiveDate,
                        $userId,
                        ['outcome' => 'new_household'],
                    );
                } else {
                    $this->historyRecorder->record(
                        $tenantId,
                        $transitionId,
                        (string) $member->id,
                        null,
                        $newFamily->id,
                        null,
                        $role,
                        'MARRIAGE',
                        $effectiveDate,
                        $userId,
                        ['outcome' => 'new_household', 'external' => true],
                    );
                }

                $placedMemberIds[] = (string) $member->id;
            }

            $this->familyRepository->syncHeadOfFamily($newFamily->id);

            foreach (array_unique($originFamilyIds) as $originFamilyId) {
                $this->successionService->resolveSuccessionForOriginFamily(
                    $originFamilyId,
                    $departingMemberIds,
                    $originSuccessions,
                    $tenantId,
                    $userId,
                    $transitionId,
                );
            }

            $this->bccTransitionService->transferFamilyToBcc(
                (int) $tenantId,
                $newFamily->fresh(),
                $targetBccId,
                $effectiveDate,
                BCCMembershipTransitionService::REASON_MARRIAGE,
                $transitionId,
                $userId,
            );

            $result = [
                'outcome' => 'new_household',
                'new_family_id' => $newFamily->id,
                'member_ids' => $placedMemberIds,
                'bcc_id' => $targetBccId,
            ];

            HouseholdTransition::create([
                'tenant_id' => $tenantId,
                'transition_id' => $transitionId,
                'type' => HouseholdTransition::TYPE_MARRIAGE,
                'status' => HouseholdTransition::STATUS_COMPLETED,
                'request_hash' => $data['request_hash'] ?? null,
                'result_summary' => $result,
                'performed_by_user_id' => $userId,
            ]);

            NewFamilyEstablishedViaMarriage::dispatch(
                (int) $tenantId,
                $newFamily->id,
                $placedMemberIds,
                $transitionId,
                $effectiveDate,
            );

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function processJoinExisting(
        array $data,
        int|string $tenantId,
        int|string $userId,
        string $transitionId,
        string $effectiveDate,
    ): array {
        $targetFamily = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($data['target_family_id'])
            ->first();

        if ($targetFamily === null) {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::TARGET_FAMILY_NOT_FOUND,
                'Target family not found.'
            );
        }

        if ($targetFamily->status === 'migrated') {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::FAMILY_ALREADY_MIGRATED,
                'Cannot join a migrated family.'
            );
        }

        if ($targetFamily->status !== 'active') {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::TARGET_FAMILY_NOT_FOUND,
                'Target family must be active.'
            );
        }

        $joining = $this->resolveJoiningMember($data, $tenantId);
        $partnerMemberId = $data['partner_member_id'] ?? null;

        if ($joining->family_id === $targetFamily->id) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::MEMBER_ALREADY_IN_TARGET_FAMILY,
                'Joining member is already in the target family.'
            );
        }

        if ($partnerMemberId) {
            $partner = $this->findActiveMember((string) $partnerMemberId, $tenantId);
            if ($partner->family_id !== $targetFamily->id) {
                throw HouseholdTransitionException::validation(
                    HouseholdTransitionException::MEMBER_NOT_IN_EXPECTED_FAMILY,
                    'Partner must be an active member of the target family.'
                );
            }
            if ($joining->id === $partner->id) {
                throw HouseholdTransitionException::validation(
                    HouseholdTransitionException::SAME_MEMBER_SELECTED,
                    'Joining member and partner cannot be the same person.'
                );
            }
        }

        $externalSpouse = $data['external_spouse'] ?? null;
        $originSuccessions = $data['origin_successions'] ?? [];
        $fromFamilyId = (string) $joining->family_id;

        return DB::transaction(function () use (
            $data,
            $tenantId,
            $userId,
            $transitionId,
            $effectiveDate,
            $joining,
            $targetFamily,
            $fromFamilyId,
            $originSuccessions,
            $externalSpouse
        ): array {
            $previousRole = (string) $joining->relationship_to_head;

            $this->familyRepository->updateMember($joining, [
                'family_id' => $targetFamily->id,
                'relationship_to_head' => 'spouse',
                'marital_status' => 'married',
                'updated_by' => $userId,
            ]);

            $this->historyRecorder->record(
                $tenantId,
                $transitionId,
                (string) $joining->id,
                $fromFamilyId,
                $targetFamily->id,
                $previousRole,
                'spouse',
                'JOIN',
                $effectiveDate,
                $userId,
                ['outcome' => 'join_existing'],
            );

            $createdSpouseId = null;
            if (is_array($externalSpouse)) {
                $person = $this->resolveExternalPerson($externalSpouse, $tenantId, $userId);
                $spouseMember = $this->familyRepository->addMember($targetFamily, $this->personService->memberIdentityFromPerson($person, [
                    'relationship_to_head' => 'spouse',
                    'status' => 'active',
                    'marital_status' => 'married',
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]));
                $createdSpouseId = (string) $spouseMember->id;

                $this->historyRecorder->record(
                    $tenantId,
                    $transitionId,
                    $createdSpouseId,
                    null,
                    $targetFamily->id,
                    null,
                    'spouse',
                    'JOIN',
                    $effectiveDate,
                    $userId,
                    ['outcome' => 'join_existing', 'external' => true],
                );
            }

            $this->successionService->resolveSuccessionForOriginFamily(
                $fromFamilyId,
                [(string) $joining->id],
                $originSuccessions,
                $tenantId,
                $userId,
                $transitionId,
            );

            $result = [
                'outcome' => 'join_existing',
                'target_family_id' => $targetFamily->id,
                'joining_member_id' => $joining->id,
                'created_spouse_member_id' => $createdSpouseId,
            ];

            HouseholdTransition::create([
                'tenant_id' => $tenantId,
                'transition_id' => $transitionId,
                'type' => HouseholdTransition::TYPE_MARRIAGE,
                'status' => HouseholdTransition::STATUS_COMPLETED,
                'request_hash' => $data['request_hash'] ?? null,
                'result_summary' => $result,
                'performed_by_user_id' => $userId,
            ]);

            MemberJoinedHouseholdViaMarriage::dispatch(
                (int) $tenantId,
                $targetFamily->id,
                (string) $joining->id,
                $transitionId,
                $effectiveDate,
            );

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, member: ?FamilyMember, person: ?Person, member_id: ?string, person_id: ?string}
     */
    private function resolveParticipant(array $data, string $side, int|string $tenantId, int|string $userId): array
    {
        $memberId = $data[$side.'_member_id'] ?? null;
        if ($memberId) {
            $member = $this->findActiveMember((string) $memberId, $tenantId);

            return [
                'key' => (string) $member->id,
                'member' => $member,
                'person' => $member->person,
                'member_id' => (string) $member->id,
                'person_id' => $member->person_id ? (string) $member->person_id : null,
            ];
        }

        $external = $data[$side.'_external'] ?? null;
        if (! is_array($external)) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::MEMBER_NOT_IN_EXPECTED_FAMILY,
                ucfirst($side).' participant must be specified as member_id or external profile.'
            );
        }

        $person = $this->resolveExternalPerson($external, $tenantId, $userId);

        return [
            'key' => 'person:'.$person->id,
            'member' => null,
            'person' => $person,
            'member_id' => null,
            'person_id' => (string) $person->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $external
     */
    private function resolveExternalPerson(array $external, int|string $tenantId, int|string $userId): Person
    {
        $existingPersonId = $external['existing_person_id'] ?? null;
        if ($existingPersonId) {
            return $this->personService->resolve((string) $existingPersonId, $tenantId);
        }

        $matches = $this->personMatchService->findPossibleMatches($tenantId, $external);
        $acknowledge = (bool) ($external['acknowledge_match'] ?? false);
        $forceCreate = (bool) ($external['force_create'] ?? false);

        if ($matches !== [] && ! $acknowledge && ! $forceCreate) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::DUPLICATE_PERSON,
                'Possible duplicate person found. Acknowledge match or select existing person.',
                ['matches' => $matches]
            );
        }

        $personResult = $this->personService->createUnlessMatches(
            $external,
            $tenantId,
            (int) $userId,
            $acknowledge,
            $forceCreate,
        );

        if ($personResult['person'] === null) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::DUPLICATE_PERSON,
                'Possible duplicate person found.',
                ['matches' => $personResult['matches']]
            );
        }

        return $personResult['person'];
    }

    /**
     * @param  array{key: string, member: ?FamilyMember, person: ?Person}  $participant
     */
    private function placeParticipantInFamily(
        array $participant,
        Family $family,
        string $role,
        int|string $tenantId,
        int|string $userId,
    ): FamilyMember {
        if ($participant['member'] !== null) {
            $member = $participant['member'];
            $this->familyRepository->updateMember($member, [
                'family_id' => $family->id,
                'relationship_to_head' => $role,
                'marital_status' => 'married',
                'updated_by' => $userId,
            ]);

            return $member->fresh();
        }

        $person = $participant['person'];
        if ($person && $this->personService->hasActiveFamilyMembership((string) $person->id)) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::DUPLICATE_PERSON,
                'Person already has an active family membership.'
            );
        }

        return $this->familyRepository->addMember($family, $this->personService->memberIdentityFromPerson($person, [
            'relationship_to_head' => $role,
            'status' => 'active',
            'marital_status' => 'married',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));
    }

    /**
     * @param  array{key: string, member: ?FamilyMember, person: ?Person}  $a
     * @param  array{key: string, member: ?FamilyMember, person: ?Person}  $b
     */
    private function assertDistinctParticipants(array $a, array $b): void
    {
        if ($a['key'] === $b['key']) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::SAME_MEMBER_SELECTED,
                'Bride and groom cannot be the same person.'
            );
        }

        if ($a['person_id'] && $b['person_id'] && $a['person_id'] === $b['person_id']) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::DUPLICATE_PERSON,
                'Both participants resolve to the same person record.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveJoiningMember(array $data, int|string $tenantId): FamilyMember
    {
        $joiningMemberId = $data['joining_member_id'] ?? null;
        if ($joiningMemberId === null) {
            throw HouseholdTransitionException::validation(
                HouseholdTransitionException::MEMBER_NOT_IN_EXPECTED_FAMILY,
                'joining_member_id is required for join_existing outcome.'
            );
        }

        return $this->findActiveMember((string) $joiningMemberId, $tenantId);
    }

    private function findActiveMember(string $memberId, int|string $tenantId): FamilyMember
    {
        $member = FamilyMember::query()
            ->whereKey($memberId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
            ->first();

        if ($member === null) {
            throw HouseholdTransitionException::notFound(
                HouseholdTransitionException::MEMBER_NOT_IN_EXPECTED_FAMILY,
                'Active family member not found in this parish.'
            );
        }

        return $member;
    }
}
