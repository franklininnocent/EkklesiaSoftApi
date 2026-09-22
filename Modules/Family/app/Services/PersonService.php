<?php

namespace Modules\Family\app\Services;

use App\Support\CaseInsensitiveSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;

class PersonService
{
    public function __construct(
        protected PersonMatchService $matchService
    ) {}

    public function resolve(string $personId, int|string $tenantId): Person
    {
        $person = Person::query()
            ->forTenant($tenantId)
            ->where('id', $personId)
            ->first();

        if (! $person) {
            throw ValidationException::withMessages([
                'person_id' => 'Person not found in this parish.',
            ]);
        }

        return $person;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int|string $tenantId, ?int $userId = null): Person
    {
        $data['tenant_id'] = $tenantId;
        $data['created_by'] = $data['created_by'] ?? $userId;
        $data['updated_by'] = $data['updated_by'] ?? $userId;
        $data['status'] = $data['status'] ?? 'active';

        return Person::query()->create($data);
    }

    /**
     * Create only after match check, or when the caller has acknowledged create-new.
     *
     * @param  array<string, mixed>  $data
     * @return array{person: Person, matches: list<array<string, mixed>>}
     */
    public function createUnlessMatches(
        array $data,
        int|string $tenantId,
        ?int $userId,
        bool $acknowledgeMatch,
        bool $forceCreate
    ): array {
        $matches = $this->matchService->findPossibleMatches($tenantId, $data);
        if ($matches !== [] && ! $acknowledgeMatch && ! $forceCreate) {
            return ['person' => null, 'matches' => $matches];
        }

        return ['person' => $this->create($data, $tenantId, $userId), 'matches' => []];
    }

    /**
     * Dual-write identity fields from Person onto a FamilyMember (Family UI compatibility).
     *
     * @return array<string, mixed>
     */
    public function memberIdentityFromPerson(Person $person, array $memberData = []): array
    {
        return array_merge($memberData, [
            'person_id' => $person->id,
            'first_name' => $memberData['first_name'] ?? $person->first_name,
            'middle_name' => array_key_exists('middle_name', $memberData) ? $memberData['middle_name'] : $person->middle_name,
            'last_name' => $memberData['last_name'] ?? $person->last_name,
            'date_of_birth' => $memberData['date_of_birth'] ?? optional($person->date_of_birth)?->format('Y-m-d'),
            'gender' => $memberData['gender'] ?? $person->gender,
            'phone' => array_key_exists('phone', $memberData) ? $memberData['phone'] : $person->phone,
            'email' => array_key_exists('email', $memberData) ? $memberData['email'] : $person->email,
        ]);
    }

    /**
     * Family/Person profile edits sync current identity onto Person.
     * Sacrament registration must never call this for an existing Person.
     *
     * @param  array<string, mixed>  $memberData
     */
    public function syncFromFamilyMember(Person $person, array $memberData, ?int $userId = null): Person
    {
        $map = [
            'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender', 'phone', 'email',
        ];
        $attrs = [];
        foreach ($map as $field) {
            if (array_key_exists($field, $memberData)) {
                $attrs[$field] = $memberData[$field];
            }
        }
        if ($attrs === []) {
            return $person;
        }
        $attrs['updated_by'] = $userId;
        $person->fill($attrs);
        $person->save();

        return $person;
    }

    /**
     * Update optional contact fields on Person and mirror to active FamilyMember when present.
     */
    public function syncContact(Person $person, ?string $email, ?string $phone, ?int $userId = null): Person
    {
        $person->email = $email;
        $person->phone = $phone;
        $person->updated_by = $userId;
        $person->save();

        $member = $person->activeFamilyMember;
        if ($member !== null) {
            $member->email = $email;
            $member->phone = $phone;
            $member->updated_by = $userId;
            $member->save();
        }

        return $person;
    }

    /**
     * Normalize optional contact fields from request payload (blank → null).
     *
     * @param  array<string, mixed>  $payload
     * @return array{email: ?string, phone: ?string}|null  null when neither key is present
     */
    public function contactFromPayload(array $payload): ?array
    {
        if (! array_key_exists('email', $payload) && ! array_key_exists('phone', $payload)) {
            return null;
        }

        return [
            'email' => array_key_exists('email', $payload)
                ? $this->normalizeContactString($payload['email'])
                : null,
            'phone' => array_key_exists('phone', $payload)
                ? $this->normalizeContactString($payload['phone'])
                : null,
        ];
    }

    private function normalizeContactString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function search(int|string $tenantId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max((int) $perPage, 1), 25);

        $query = Person::query()
            ->forTenant($tenantId)
            ->whereNull('deleted_at')
            ->with([
                'activeFamilyMember.family:id,family_name,family_code,bcc_id',
                'activeFamilyMember.family.bcc:id,name,bcc_code',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            if (\Illuminate\Support\Str::isUuid($search)) {
                $query->where('id', $search);
            } else {
                $pattern = '%'.$search.'%';
                $query->where(function ($q) use ($pattern, $search) {
                    CaseInsensitiveSearch::applyColumnLike($q, 'first_name', $pattern);
                    CaseInsensitiveSearch::applyColumnLike($q, 'last_name', $pattern, 'or');
                    CaseInsensitiveSearch::applyMemberFullNameLike($q, $pattern, 'or');
                    $q->orWhereHas('activeFamilyMember.family', function ($familyQuery) use ($search) {
                        $familyQuery->where('family_code', 'ilike', '%'.$search.'%');
                    });
                });
            }
        } elseif (strlen($search) > 0 && strlen($search) < 2) {
            $query->whereRaw('1 = 0');
        }

        if (! empty($filters['unaffiliated'])) {
            $query->whereDoesntHave('familyMembers', fn ($q) => $q->whereNull('deleted_at'));
        }

        if (! empty($filters['exclude_person_id'])) {
            $query->where('id', '!=', (string) $filters['exclude_person_id']);
        }

        return $query->orderBy('last_name')->orderBy('first_name')->paginate($perPage);
    }

    public function hasActiveFamilyMembership(string $personId, ?string $excludeMemberId = null): bool
    {
        $query = FamilyMember::query()
            ->where('person_id', $personId)
            ->whereNull('deleted_at');

        if ($excludeMemberId) {
            $query->where('id', '!=', $excludeMemberId);
        }

        return $query->exists();
    }
}
