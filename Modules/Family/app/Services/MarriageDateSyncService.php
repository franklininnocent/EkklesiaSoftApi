<?php

namespace Modules\Family\app\Services;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;

/**
 * Resolve one shared marriage profile for uniquely linked spouses and keep
 * their family_members marriage fields aligned. Never creates or updates
 * sacrament register rows. Does not attach a marriage to another person when
 * the spouse link is missing or ambiguous.
 */
class MarriageDateSyncService
{
    public const CONFLICT_CODE = 'marriage_date_conflict';

    /** @var list<string> */
    private const SHARED_PROFILE_FIELDS = [
        'marriage_place',
        'marriage_bride_full_name',
        'marriage_bride_father_name',
        'marriage_bride_mother_name',
        'marriage_bride_address',
        'marriage_bride_church_type',
        'marriage_bride_church_name',
        'marriage_bride_church_address',
        'marriage_groom_full_name',
        'marriage_groom_father_name',
        'marriage_groom_mother_name',
        'marriage_groom_address',
        'marriage_groom_church_type',
        'marriage_groom_church_name',
        'marriage_groom_church_address',
    ];

    private const CONTEXT_KEYS = [
        'acknowledge_marriage_date_conflict',
        'linked_spouse_member_id',
        'linked_spouse_family_id',
        'linked_spouse_marriage_date',
        'linked_spouse_name',
        'matrimony_register_date',
        'marriage_date_conflict',
        'suggested_marriage_date',
        'marriage_resolution_needed',
        'marriage_resolved_from_spouse',
    ];

    public function __construct(
        protected FamilyRepository $familyRepository,
        protected FamilyAuditService $familyAuditService,
    ) {}

    /**
     * @param  iterable<FamilyMember>  $members
     */
    public function attachToMembers(iterable $members, string $tenantId): void
    {
        $memberList = $members instanceof Collection ? $members : collect($members);
        if ($memberList->isEmpty()) {
            return;
        }

        $expanded = $this->withHouseholdPeers($memberList, $tenantId);
        $householdSpouseByMemberId = $this->householdSpouseMap($expanded);
        $ambiguousFamilyIds = $this->ambiguousHouseholdFamilyIds($expanded);
        $registerByMemberId = $this->loadRegisterIndex($expanded, $tenantId);

        foreach ($memberList as $member) {
            $memberId = (string) $member->id;
            $householdSpouse = $householdSpouseByMemberId->get($memberId);
            $register = $registerByMemberId[$memberId] ?? ['dates' => [], 'places' => [], 'spouse' => null];

            $spouse = $householdSpouse instanceof FamilyMember
                ? $householdSpouse
                : ($register['spouse'] instanceof FamilyMember ? $register['spouse'] : null);

            if ($spouse && (string) $spouse->id === $memberId) {
                $spouse = null;
            }

            $memberDate = $this->normalizeDate($member->marriage_date);
            $spouseDate = $this->normalizeDate($spouse?->marriage_date);
            $registerDates = $register['dates'] ?? [];
            $registerDate = count($registerDates) === 1 ? $registerDates[0] : null;
            $registerPlaces = $register['places'] ?? [];
            $registerPlace = count($registerPlaces) === 1 ? $registerPlaces[0] : null;

            $unique = $this->uniqueDates([$memberDate, $spouseDate, ...$registerDates]);
            $conflict = count($unique) > 1 || count($registerDates) > 1;
            $suggested = $memberDate ?? $spouseDate ?? $registerDate;
            $ambiguousHousehold = $ambiguousFamilyIds->contains((string) $member->family_id);

            $member->setAttribute('linked_spouse_member_id', $spouse?->id);
            $member->setAttribute('linked_spouse_family_id', $spouse?->family_id);
            $member->setAttribute('linked_spouse_marriage_date', $spouseDate);
            $member->setAttribute('linked_spouse_name', $spouse?->full_name_display);
            $member->setAttribute('matrimony_register_date', $registerDate);
            $member->setAttribute('marriage_date_conflict', $conflict);
            $member->setAttribute('suggested_marriage_date', $suggested);

            $this->overlaySharedMarriageForDisplay(
                $member,
                $spouse,
                $suggested,
                $registerPlace,
                $conflict,
                $ambiguousHousehold
            );
        }
    }

    /**
     * Validate conflict, strip client context keys, ignore marriage_date when not married.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertAndPrepare(?FamilyMember $existing, array &$data, string $familyId, string $tenantId): ?FamilyMember
    {
        $acknowledged = $this->booleanFlag($data['acknowledge_marriage_date_conflict'] ?? false);
        $this->stripContextKeys($data);

        $incomingStatus = array_key_exists('marital_status', $data)
            ? strtolower((string) ($data['marital_status'] ?? ''))
            : null;
        if ($incomingStatus !== null && $incomingStatus !== 'married') {
            unset($data['marriage_date']);

            return null;
        }

        if (! $this->payloadTouchesSharedMarriage($data)) {
            return null;
        }

        $context = $this->resolveWriteContext($existing, $data, $familyId, $tenantId);
        $spouse = $context['spouse'];
        $registerDates = $context['register_dates'];

        if ($spouse instanceof FamilyMember && (string) $spouse->id === (string) ($existing?->id ?? '')) {
            $spouse = null;
        }

        $dateConflict = false;
        if (array_key_exists('marriage_date', $data)) {
            $submitted = $this->normalizeDate($data['marriage_date']);
            $spouseDate = $this->normalizeDate($spouse?->marriage_date);
            $memberDate = $this->normalizeDate($existing?->marriage_date);
            $wouldOverwriteSpouse = $spouse !== null
                && $spouseDate !== null
                && $spouseDate !== $submitted
                && $spouseDate !== $memberDate;
            $registerDiffers = $registerDates !== []
                && (count($registerDates) > 1 || $registerDates[0] !== $submitted);
            $dateConflict = $wouldOverwriteSpouse || $registerDiffers;
        }

        $profileConflict = $this->submittedSharedFieldsConflictWithSpouse($data, $existing, $spouse);

        if (($dateConflict || $profileConflict) && ! $acknowledged) {
            throw ValidationException::withMessages([
                'marriage_date' => [
                    'This marriage information is different on the linked spouse or in the parish marriage register. Confirm to update both member records. The parish marriage register will not be changed.',
                ],
                'conflict' => [self::CONFLICT_CODE],
            ]);
        }

        if ($spouse instanceof FamilyMember) {
            $linkedName = trim((string) $spouse->full_name_display);
            if ($linkedName !== '') {
                $submittedSpouseName = $this->normalizeText($data['marriage_spouse_name'] ?? null);
                $selfName = $this->normalizeText($existing?->full_name_display);
                if ($submittedSpouseName === null || $submittedSpouseName === $selfName) {
                    $data['marriage_spouse_name'] = $linkedName;
                }
            }
        }

        return $spouse;
    }

    /**
     * Persist the linked spouse date and audit both profiles that actually changed.
     * Must run inside the caller's database transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncLinkedSpouseAndAudit(
        FamilyMember $member,
        ?FamilyMember $spouse,
        mixed $previousMemberDate,
        array $data,
        string $tenantId,
        string $userId
    ): void {
        if (! $this->payloadTouchesSharedMarriage($data)) {
            return;
        }

        $newDate = array_key_exists('marriage_date', $data)
            ? $this->normalizeDate($data['marriage_date'])
            : $this->normalizeDate($member->marriage_date);
        $oldDate = $this->normalizeDate($previousMemberDate);

        if (array_key_exists('marriage_date', $data) && $oldDate !== $newDate) {
            $this->audit($tenantId, $member, $oldDate, $newDate, $spouse?->id);
        }

        if ($spouse === null || (string) $spouse->id === (string) $member->id) {
            return;
        }

        $family = $this->familyRepository->findById((string) $spouse->family_id, $tenantId);
        if (! $family) {
            throw ValidationException::withMessages([
                'marriage_date' => ['The linked spouse could not be updated because they are not in this parish.'],
            ]);
        }

        $payload = $this->sharedMarriagePayloadForSpouse($data, $member, $userId);
        if ($payload === []) {
            return;
        }

        $spouseOld = $this->normalizeDate($spouse->marriage_date);
        $updated = $this->familyRepository->updateMember($spouse, $payload);

        if (! $updated) {
            throw new \RuntimeException('Failed to update linked spouse marriage profile.');
        }

        $spouseNew = array_key_exists('marriage_date', $payload)
            ? $this->normalizeDate($payload['marriage_date'])
            : $spouseOld;
        if ($spouseOld !== $spouseNew) {
            $this->audit($tenantId, $spouse, $spouseOld, $spouseNew, $member->id);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payloadTouchesSharedMarriage(array $data): bool
    {
        if (array_key_exists('marriage_date', $data) || array_key_exists('marriage_spouse_name', $data)) {
            return true;
        }

        foreach (self::SHARED_PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submittedSharedFieldsConflictWithSpouse(array $data, ?FamilyMember $existing, ?FamilyMember $spouse): bool
    {
        if (! $spouse instanceof FamilyMember) {
            return false;
        }

        foreach (self::SHARED_PROFILE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $submitted = $this->normalizeText($data[$field] ?? null);
            $spouseValue = $this->normalizeText($spouse->{$field} ?? null);
            $memberValue = $this->normalizeText($existing?->{$field} ?? null);

            if ($spouseValue !== null && $submitted !== $spouseValue && $spouseValue !== $memberValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sharedMarriagePayloadForSpouse(array $data, FamilyMember $member, string $userId): array
    {
        $payload = ['updated_by' => $userId];
        $changed = false;

        if (array_key_exists('marriage_date', $data)) {
            $payload['marriage_date'] = $data['marriage_date'];
            $changed = true;
        }

        foreach (self::SHARED_PROFILE_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $payload[$field] = $data[$field];
            $changed = true;
        }

        $memberName = trim((string) $member->full_name_display);
        if ($memberName !== '') {
            $payload['marriage_spouse_name'] = $memberName;
            $changed = true;
        }

        return $changed ? $payload : [];
    }

    private function overlaySharedMarriageForDisplay(
        FamilyMember $member,
        ?FamilyMember $spouse,
        ?string $suggestedDate,
        ?string $registerPlace,
        bool $conflict,
        bool $ambiguousHousehold
    ): void {
        $resolutionNeeded = $ambiguousHousehold
            && ($this->isHeadRelationship($member->relationship_to_head)
                || $this->isSpouseRelationship($member->relationship_to_head));
        $resolvedFromSpouse = false;

        if ($spouse instanceof FamilyMember && ! $ambiguousHousehold) {
            if ($this->normalizeDate($member->marriage_date) === null && $suggestedDate !== null && ! $conflict) {
                $this->overlayColumn($member, 'marriage_date', $suggestedDate);
                $resolvedFromSpouse = true;
            }

            foreach (self::SHARED_PROFILE_FIELDS as $field) {
                $mine = $this->normalizeText($member->{$field} ?? null);
                $theirs = $this->normalizeText($spouse->{$field} ?? null);
                if ($mine !== null && $theirs !== null && $mine !== $theirs) {
                    $conflict = true;
                    $resolutionNeeded = true;

                    continue;
                }
                if ($mine === null && $theirs !== null) {
                    $this->overlayColumn($member, $field, $spouse->{$field});
                    $resolvedFromSpouse = true;
                }
            }

            if ($this->normalizeText($member->marriage_place ?? null) === null && $registerPlace !== null && ! $conflict) {
                $this->overlayColumn($member, 'marriage_place', $registerPlace);
            }

            $linkedName = trim((string) $spouse->full_name_display);
            if ($linkedName !== '') {
                $this->overlayColumn($member, 'marriage_spouse_name', $linkedName);
                $resolvedFromSpouse = true;
            }
        } elseif ($ambiguousHousehold) {
            $resolutionNeeded = true;
        } else {
            if ($this->normalizeDate($member->marriage_date) === null && $suggestedDate !== null && ! $conflict) {
                $this->overlayColumn($member, 'marriage_date', $suggestedDate);
            }
            if ($this->normalizeText($member->marriage_place ?? null) === null && $registerPlace !== null && ! $conflict) {
                $this->overlayColumn($member, 'marriage_place', $registerPlace);
            }
        }

        $member->setAttribute('marriage_date_conflict', $conflict);
        $member->setAttribute('marriage_resolution_needed', $resolutionNeeded);
        $member->setAttribute('marriage_resolved_from_spouse', $resolvedFromSpouse);
    }

    private function overlayColumn(FamilyMember $member, string $column, mixed $value): void
    {
        $member->setAttribute($column, $value);
        $member->syncOriginalAttribute($column);
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return Collection<int, FamilyMember>
     */
    private function withHouseholdPeers(Collection $members, string $tenantId): Collection
    {
        $familyIds = $members->pluck('family_id')->filter()->unique()->values();
        if ($familyIds->isEmpty()) {
            return $members;
        }

        $knownIds = $members->pluck('id')->map(fn ($id) => (string) $id)->all();
        $peers = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('family_id', $familyIds->all())
            ->where('status', 'active')
            ->whereNotIn('id', $knownIds)
            ->get();

        return $peers->isEmpty() ? $members : $members->concat($peers)->values();
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return Collection<int, string>
     */
    private function ambiguousHouseholdFamilyIds(Collection $members): Collection
    {
        $ids = collect();
        foreach ($members->groupBy(fn (FamilyMember $member) => (string) $member->family_id) as $familyId => $familyMembers) {
            $active = $familyMembers->filter(
                fn (FamilyMember $member) => strtolower((string) $member->status) === 'active'
            );
            $heads = $active->filter(fn (FamilyMember $member) => $this->isHeadRelationship($member->relationship_to_head));
            $spouses = $active->filter(fn (FamilyMember $member) => $this->isSpouseRelationship($member->relationship_to_head));
            if ($heads->count() > 1 || $spouses->count() > 1) {
                $ids->push((string) $familyId);
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return Collection<string, FamilyMember>
     */
    private function householdSpouseMap(Collection $members): Collection
    {
        $byFamily = $members->groupBy(fn (FamilyMember $member) => (string) $member->family_id);
        $map = collect();

        foreach ($byFamily as $familyMembers) {
            $pair = $this->uniqueHouseholdPair($familyMembers);
            if ($pair === null) {
                continue;
            }

            [$head, $spouse] = $pair;
            $map->put((string) $head->id, $spouse);
            $map->put((string) $spouse->id, $head);
        }

        return $map;
    }

    /**
     * @param  Collection<int, FamilyMember>  $familyMembers
     * @return array{0: FamilyMember, 1: FamilyMember}|null
     */
    private function uniqueHouseholdPair(Collection $familyMembers): ?array
    {
        $active = $familyMembers->filter(
            fn (FamilyMember $member) => strtolower((string) $member->status) === 'active'
        );

        $heads = $active->filter(fn (FamilyMember $member) => $this->isHeadRelationship($member->relationship_to_head))->values();
        $spouses = $active->filter(fn (FamilyMember $member) => $this->isSpouseRelationship($member->relationship_to_head))->values();

        if ($heads->count() !== 1 || $spouses->count() !== 1) {
            return null;
        }

        return [$heads->first(), $spouses->first()];
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return array<string, array{dates: list<string>, spouse: ?FamilyMember}>
     */
    private function loadRegisterIndex(Collection $members, string $tenantId): array
    {
        $index = [];
        foreach ($members as $member) {
            $index[(string) $member->id] = ['dates' => [], 'places' => [], 'spouse' => null];
        }

        if (! class_exists(Sacrament::class)) {
            return $index;
        }

        $memberIds = $members->pluck('id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $personIds = $members->pluck('person_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $membersByPersonId = $members->filter(fn (FamilyMember $member) => filled($member->person_id))
            ->keyBy(fn (FamilyMember $member) => (string) $member->person_id);

        $typeIds = $this->matrimonyTypeIds();
        if ($typeIds === []) {
            return $index;
        }

        $sacraments = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('sacrament_type_id', $typeIds)
            ->whereNull('deleted_at')
            ->whereHas('participants', function ($query) use ($memberIds, $personIds) {
                $query->whereIn('role', ['bride', 'groom'])
                    ->where(function ($inner) use ($memberIds, $personIds) {
                        $inner->whereIn('family_member_id', $memberIds);
                        if ($personIds !== []) {
                            $inner->orWhereIn('person_id', $personIds);
                        }
                    });
            })
            ->with(['participants', 'sacramentType'])
            ->get();

        $missingSpouseKeys = [];

        foreach ($sacraments as $sacrament) {
            if (SacramentStatus::normalize((string) $sacrament->status) === SacramentStatus::VOIDED) {
                continue;
            }

            $date = $this->normalizeDate($sacrament->date_administered);
            $parties = $this->brideGroomParticipants($sacrament);
            if ($parties === []) {
                continue;
            }

            foreach ($members as $member) {
                $self = $this->participantForMember($parties, $member);
                if ($self === null) {
                    continue;
                }

                $memberId = (string) $member->id;
                if ($date !== null && ! in_array($date, $index[$memberId]['dates'], true)) {
                    $index[$memberId]['dates'][] = $date;
                }

                $place = trim((string) ($sacrament->place_administered ?? ''));
                if ($place !== '' && ! collect($index[$memberId]['places'])->contains(
                    fn ($existing) => $this->normalizeText($existing) === $this->normalizeText($place)
                )) {
                    $index[$memberId]['places'][] = $place;
                }

                $other = $this->otherParticipant($parties, $self);
                if ($other === null || $index[$memberId]['spouse'] instanceof FamilyMember) {
                    continue;
                }

                $resolved = $this->memberFromParticipant($other, $members, $membersByPersonId);
                if ($resolved instanceof FamilyMember) {
                    $index[$memberId]['spouse'] = $resolved;
                } else {
                    $missingSpouseKeys[$memberId][] = $other;
                }
            }
        }

        $this->hydrateMissingRegisterSpouses($index, $missingSpouseKeys, $tenantId, $members);

        foreach ($index as &$entry) {
            $entry['dates'] = $this->uniqueDates($entry['dates']);
        }
        unset($entry);

        return $index;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{spouse: ?FamilyMember, register_dates: list<string>}
     */
    private function resolveWriteContext(?FamilyMember $existing, array $data, string $familyId, string $tenantId): array
    {
        $relationship = (string) ($data['relationship_to_head'] ?? $existing?->relationship_to_head ?? '');
        $personId = (string) ($data['person_id'] ?? $existing?->person_id ?? '');
        $memberId = $existing !== null ? (string) $existing->id : '';

        $household = FamilyMember::query()
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->get();

        $pair = $this->uniqueHouseholdPair($household);
        $spouse = null;
        if ($pair !== null) {
            [$head, $householdSpouse] = $pair;
            if ($this->isHeadRelationship($relationship)) {
                $spouse = $householdSpouse;
            } elseif ($this->isSpouseRelationship($relationship)) {
                $spouse = $head;
            } elseif ($memberId !== '') {
                if ((string) $head->id === $memberId) {
                    $spouse = $householdSpouse;
                } elseif ((string) $householdSpouse->id === $memberId) {
                    $spouse = $head;
                }
            }
        } else {
            $heads = $household->filter(fn (FamilyMember $row) => $this->isHeadRelationship($row->relationship_to_head) && strtolower((string) $row->status) === 'active')->values();
            $spouses = $household->filter(fn (FamilyMember $row) => $this->isSpouseRelationship($row->relationship_to_head) && strtolower((string) $row->status) === 'active')->values();
            if ($memberId === '' && $this->isSpouseRelationship($relationship) && $heads->count() === 1 && $spouses->count() === 0) {
                $spouse = $heads->first();
            } elseif ($memberId === '' && $this->isHeadRelationship($relationship) && $spouses->count() === 1 && $heads->count() === 0) {
                $spouse = $spouses->first();
            }
        }

        if ($spouse && $memberId !== '' && (string) $spouse->id === $memberId) {
            $spouse = null;
        }

        $register = $this->registerDatesAndSpouseForIdentity($memberId, $personId, $tenantId, $existing);
        if ($spouse === null) {
            $spouse = $register['spouse'];
        }

        return [
            'spouse' => $spouse,
            'register_dates' => $register['dates'],
        ];
    }

    /**
     * @return array{dates: list<string>, spouse: ?FamilyMember}
     */
    private function registerDatesAndSpouseForIdentity(
        string $memberId,
        string $personId,
        string $tenantId,
        ?FamilyMember $existing
    ): array {
        $result = ['dates' => [], 'spouse' => null];
        if (! class_exists(Sacrament::class) || ($memberId === '' && $personId === '')) {
            return $result;
        }

        $typeIds = $this->matrimonyTypeIds();
        if ($typeIds === []) {
            return $result;
        }

        $sacraments = Sacrament::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('sacrament_type_id', $typeIds)
            ->whereNull('deleted_at')
            ->whereHas('participants', function ($query) use ($memberId, $personId) {
                $query->whereIn('role', ['bride', 'groom'])
                    ->where(function ($inner) use ($memberId, $personId) {
                        if ($memberId !== '') {
                            $inner->where('family_member_id', $memberId);
                        }
                        if ($personId !== '') {
                            $inner->orWhere('person_id', $personId);
                        }
                    });
            })
            ->with('participants')
            ->get();

        foreach ($sacraments as $sacrament) {
            if (SacramentStatus::normalize((string) $sacrament->status) === SacramentStatus::VOIDED) {
                continue;
            }

            $date = $this->normalizeDate($sacrament->date_administered);
            if ($date !== null && ! in_array($date, $result['dates'], true)) {
                $result['dates'][] = $date;
            }

            if ($result['spouse'] instanceof FamilyMember) {
                continue;
            }

            $parties = $this->brideGroomParticipants($sacrament);
            $self = null;
            foreach ($parties as $participant) {
                if ($memberId !== '' && (string) $participant->family_member_id === $memberId) {
                    $self = $participant;
                    break;
                }
                if ($personId !== '' && (string) $participant->person_id === $personId) {
                    $self = $participant;
                    break;
                }
            }
            if ($self === null) {
                continue;
            }

            $other = $this->otherParticipant($parties, $self);
            if ($other === null) {
                continue;
            }

            $result['spouse'] = $this->loadTenantMemberFromParticipant($other, $tenantId, $existing);
        }

        return $result;
    }

    /**
     * @return list<int|string>
     */
    private function matrimonyTypeIds(): array
    {
        if (! class_exists(SacramentType::class)) {
            return [];
        }

        return SacramentType::query()
            ->get(['id', 'code'])
            ->filter(fn (SacramentType $type) => SacramentTypeCode::isMatrimony($type->code))
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<object>
     */
    private function brideGroomParticipants(Sacrament $sacrament): array
    {
        return $sacrament->participants
            ->filter(fn ($participant) => in_array($participant->role, ['bride', 'groom'], true))
            ->values()
            ->all();
    }

    private function participantForMember(array $parties, FamilyMember $member): mixed
    {
        $memberId = (string) $member->id;
        $personId = (string) ($member->person_id ?? '');

        foreach ($parties as $participant) {
            if ((string) $participant->family_member_id === $memberId) {
                return $participant;
            }
            if ($personId !== '' && (string) $participant->person_id === $personId) {
                return $participant;
            }
        }

        return null;
    }

    private function otherParticipant(array $parties, mixed $self): mixed
    {
        foreach ($parties as $participant) {
            if ($participant === $self) {
                continue;
            }
            if ((string) $participant->role === (string) $self->role) {
                continue;
            }

            return $participant;
        }

        return null;
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @param  Collection<string, FamilyMember>  $membersByPersonId
     */
    private function memberFromParticipant(mixed $participant, Collection $members, Collection $membersByPersonId): ?FamilyMember
    {
        $memberId = (string) ($participant->family_member_id ?? '');
        if ($memberId !== '') {
            $match = $members->first(fn (FamilyMember $member) => (string) $member->id === $memberId);
            if ($match instanceof FamilyMember) {
                return $match;
            }
        }

        $personId = (string) ($participant->person_id ?? '');
        if ($personId !== '' && $membersByPersonId->has($personId)) {
            return $membersByPersonId->get($personId);
        }

        return null;
    }

    /**
     * @param  array<string, array{dates: list<string>, places: list<string>, spouse: ?FamilyMember}>  $index
     * @param  array<string, list<mixed>>  $missingSpouseKeys
     */
    private function hydrateMissingRegisterSpouses(
        array &$index,
        array $missingSpouseKeys,
        string $tenantId,
        Collection $knownMembers
    ): void {
        if ($missingSpouseKeys === []) {
            return;
        }

        $memberIds = [];
        $personIds = [];
        foreach ($missingSpouseKeys as $participants) {
            foreach ($participants as $participant) {
                if (! empty($participant->family_member_id)) {
                    $memberIds[] = (string) $participant->family_member_id;
                }
                if (! empty($participant->person_id)) {
                    $personIds[] = (string) $participant->person_id;
                }
            }
        }

        $knownIds = $knownMembers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $query = FamilyMember::query()->where('tenant_id', $tenantId)->where('status', 'active');
        $query->where(function ($inner) use ($memberIds, $personIds) {
            if ($memberIds !== []) {
                $inner->whereIn('id', array_unique($memberIds));
            }
            if ($personIds !== []) {
                $inner->orWhereIn('person_id', array_unique($personIds));
            }
        });

        $loaded = $query->get()->keyBy(fn (FamilyMember $member) => (string) $member->id);

        foreach ($missingSpouseKeys as $memberId => $participants) {
            if ($index[$memberId]['spouse'] instanceof FamilyMember) {
                continue;
            }
            foreach ($participants as $participant) {
                $resolved = null;
                $otherMemberId = (string) ($participant->family_member_id ?? '');
                if ($otherMemberId !== '' && $loaded->has($otherMemberId) && $otherMemberId !== $memberId) {
                    $resolved = $loaded->get($otherMemberId);
                }
                $personId = (string) ($participant->person_id ?? '');
                if ($resolved === null && $personId !== '') {
                    $resolved = $loaded->first(
                        fn (FamilyMember $member) => (string) $member->person_id === $personId
                            && (string) $member->id !== $memberId
                    );
                }
                if ($resolved instanceof FamilyMember && ! in_array((string) $resolved->id, $knownIds, true)) {
                    $index[$memberId]['spouse'] = $resolved;
                    break;
                }
                if ($resolved instanceof FamilyMember) {
                    $index[$memberId]['spouse'] = $resolved;
                    break;
                }
            }
        }
    }

    private function loadTenantMemberFromParticipant(mixed $participant, string $tenantId, ?FamilyMember $existing): ?FamilyMember
    {
        $excludeId = $existing !== null ? (string) $existing->id : '';
        $memberId = (string) ($participant->family_member_id ?? '');
        if ($memberId !== '' && $memberId !== $excludeId) {
            $match = FamilyMember::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $memberId)
                ->where('status', 'active')
                ->first();
            if ($match) {
                return $match;
            }
        }

        $personId = (string) ($participant->person_id ?? '');
        if ($personId === '') {
            return null;
        }

        return FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('status', 'active')
            ->when($excludeId !== '', fn ($query) => $query->where('id', '!=', $excludeId))
            ->first();
    }

    private function isHeadRelationship(?string $relationship): bool
    {
        return in_array(strtolower((string) $relationship), ['self', 'head'], true);
    }

    private function isSpouseRelationship(?string $relationship): bool
    {
        return strtolower((string) $relationship) === 'spouse';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stripContextKeys(array &$data): void
    {
        foreach (self::CONTEXT_KEYS as $key) {
            unset($data[$key]);
        }
    }

    private function booleanFlag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  list<string|null>  $dates
     * @return list<string>
     */
    private function uniqueDates(array $dates): array
    {
        $unique = [];
        foreach ($dates as $date) {
            if ($date === null || $date === '') {
                continue;
            }
            if (! in_array($date, $unique, true)) {
                $unique[] = $date;
            }
        }

        return $unique;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return mb_strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, 'T')) {
            $raw = explode('T', $raw)[0];
        } elseif (str_contains($raw, ' ')) {
            $raw = explode(' ', $raw)[0];
        }

        return $raw !== '' ? $raw : null;
    }

    private function audit(
        string $tenantId,
        FamilyMember $member,
        ?string $oldDate,
        ?string $newDate,
        ?string $linkedMemberId
    ): void {
        $this->familyAuditService->log(
            (int) $tenantId,
            'family_member.marriage_date_updated',
            'family_member',
            (string) $member->id,
            ['marriage_date' => $oldDate],
            ['marriage_date' => $newDate],
            [
                'family_id' => (string) $member->family_id,
                'linked_spouse_member_id' => $linkedMemberId,
            ]
        );
    }
}
