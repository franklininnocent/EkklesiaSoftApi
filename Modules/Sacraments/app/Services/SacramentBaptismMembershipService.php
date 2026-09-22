<?php

namespace Modules\Sacraments\Services;

use Illuminate\Support\Facades\Gate;
use Modules\Authentication\Models\User;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Family\app\Services\FamilyService;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Support\SacramentParticipantRole;
use Modules\Sacraments\Support\SacramentParticipantSource;

/**
 * Establishes official parish membership from a Baptism register record (date_administered).
 * Runs after the sacrament row is persisted, inside the same DB transaction.
 */
class SacramentBaptismMembershipService
{
    public function __construct(
        protected FamilyService $familyService
    ) {}

    /**
     * @param  array<string, mixed>  $data  Pre-strip request data (family_association, deferred flags, etc.)
     */
    public function establishAfterCreate(
        Sacrament $sacrament,
        array $data,
        int $tenantId,
        ?int $userId
    ): void {
        $association = $data['family_association'] ?? null;
        if ($association === null || $association === 'none') {
            return;
        }

        $deferred = $data['_baptism_membership_deferred'] ?? null;

        if ($deferred === 'link_existing') {
            $this->linkToExistingFamily($sacrament, $data, $tenantId, $userId);

            return;
        }

        if ($deferred === 'create_new') {
            $this->createNewFamilyMembership($sacrament, $data, $tenantId, $userId);

            return;
        }

        $memberId = $data['family_member_id'] ?? null;
        if ($memberId) {
            $member = FamilyMember::query()
                ->where('id', $memberId)
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if ($member) {
                $this->stampMemberFromRegister($member, $sacrament);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkToExistingFamily(
        Sacrament $sacrament,
        array $data,
        int $tenantId,
        ?int $userId
    ): void {
        $familyId = (string) ($data['family_id'] ?? '');
        $personId = (string) ($sacrament->person_id ?? '');
        if ($familyId === '' || $personId === '') {
            throw new SacramentBusinessRuleException(
                'family_link_incomplete',
                'Family and Person are required to establish membership after Baptism.'
            );
        }

        $family = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $familyId)
            ->first();

        if (! $family) {
            throw new SacramentBusinessRuleException(
                'cross_tenant_family',
                'Family not found in this parish.'
            );
        }

        $this->assertCanEditFamily($family);

        $relationship = trim((string) ($data['relationship_to_head'] ?? ''));
        if ($relationship === '') {
            throw new SacramentBusinessRuleException(
                'relationship_required',
                'Relationship to family head is required when adding a new person to an existing family.'
            );
        }

        $existingMember = FamilyMember::query()
            ->where('person_id', $personId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->first();

        if ($existingMember) {
            if ((string) $existingMember->family_id === $familyId) {
                $this->stampMemberFromRegister($existingMember, $sacrament);
                $this->syncSacramentFamilyContext($sacrament, $existingMember, $tenantId);

                return;
            }

            throw new SacramentBusinessRuleException(
                'person_already_in_family',
                'This person already belongs to another family.'
            );
        }

        $member = $this->familyService->linkPersonToFamily(
            $familyId,
            $personId,
            [
                'relationship_to_head' => $relationship,
                'baptism_date' => $this->registerDate($sacrament),
                'baptism_place' => $sacrament->place_administered,
            ],
            $tenantId,
            (int) $userId
        );

        $this->syncSacramentFamilyContext($sacrament, $member, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createNewFamilyMembership(
        Sacrament $sacrament,
        array $data,
        int $tenantId,
        ?int $userId
    ): void {
        $this->assertCanCreateFamily();

        $familyInput = $data['family'] ?? null;
        if (! is_array($familyInput) || trim((string) ($familyInput['family_name'] ?? '')) === '') {
            throw new SacramentBusinessRuleException(
                'family_required',
                'New family details are required.'
            );
        }

        $person = Person::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $sacrament->person_id)
            ->first();

        if (! $person) {
            throw new SacramentBusinessRuleException(
                'recipient_person_required',
                'Baptism must belong to a parish Person before creating a family.'
            );
        }

        $created = $this->familyService->createFamilyWithPerson(
            $familyInput,
            $person,
            [
                'relationship_to_head' => $data['relationship_to_head'] ?? 'self',
                'baptism_date' => $this->registerDate($sacrament),
                'baptism_place' => $sacrament->place_administered,
            ],
            $tenantId,
            (int) $userId
        );

        $this->syncSacramentFamilyContext($sacrament, $created['member'], $tenantId);
    }

    public function stampMemberFromRegister(FamilyMember $member, Sacrament $sacrament): void
    {
        if ($member->baptism_date !== null) {
            return;
        }

        $member->update([
            'baptism_date' => $this->registerDate($sacrament),
            'baptism_place' => $sacrament->place_administered,
        ]);
    }

    private function syncSacramentFamilyContext(
        Sacrament $sacrament,
        FamilyMember $member,
        int $tenantId
    ): void {
        $sacrament->update([
            'family_id' => $member->family_id,
        ]);

        SacramentParticipant::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacrament->id)
            ->where('role', SacramentParticipantRole::RECIPIENT)
            ->update([
                'source' => SacramentParticipantSource::MEMBER,
                'person_id' => $member->person_id,
                'family_member_id' => $member->id,
            ]);
    }

    private function registerDate(Sacrament $sacrament): ?string
    {
        $date = $sacrament->date_administered;
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return is_string($date) && $date !== '' ? $date : null;
    }

    private function assertCanEditFamily(Family $family): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new SacramentBusinessRuleException(
                'unauthenticated',
                'Authentication is required.',
                [],
                401
            );
        }

        if (! Gate::forUser($user)->allows('update', $family)) {
            throw new SacramentBusinessRuleException(
                'family_edit_forbidden',
                'You do not have permission to add members to this family.',
                [],
                403
            );
        }
    }

    private function assertCanCreateFamily(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new SacramentBusinessRuleException(
                'unauthenticated',
                'Authentication is required.',
                [],
                401
            );
        }

        if (! Gate::forUser($user)->allows('create', Family::class)) {
            throw new SacramentBusinessRuleException(
                'family_create_forbidden',
                'You do not have permission to create a family.',
                [],
                403
            );
        }
    }
}
