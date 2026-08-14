<?php

namespace Modules\Family\app\Services;

use Carbon\Carbon;
use Modules\Family\Models\Person;

/**
 * Deterministic, tenant-scoped person matching. No fuzzy/AI. Never auto-merges.
 */
class PersonMatchService
{
    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function findPossibleMatches(int|string $tenantId, array $input, ?string $excludePersonId = null): array
    {
        $first = $this->normalizeName((string) ($input['first_name'] ?? ''));
        $last = $this->normalizeName((string) ($input['last_name'] ?? ''));
        $dob = $this->normalizeDate($input['date_of_birth'] ?? null);
        $gender = $this->normalizeGender($input['gender'] ?? null);
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        $email = $this->normalizeEmail((string) ($input['email'] ?? ''));
        $father = $this->normalizeName((string) ($input['father_name'] ?? ''));
        $mother = $this->normalizeName((string) ($input['mother_name'] ?? ''));

        if ($first === '' || $last === '') {
            return [];
        }

        $query = Person::query()->forTenant($tenantId)->whereNull('deleted_at');
        if ($excludePersonId) {
            $query->where('id', '!=', $excludePersonId);
        }

        $candidates = $query
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$first])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$last])
            ->limit(25)
            ->get();

        $matches = $candidates->filter(function (Person $person) use ($dob, $gender, $phone, $email, $father, $mother) {
            $strength = $this->strength($person, $dob, $gender, $phone, $email, $father, $mother);

            return $strength !== null;
        })->map(function (Person $person) use ($dob, $gender, $phone, $email, $father, $mother) {
            return [
                'id' => $person->id,
                'full_name' => $person->full_name_display,
                'first_name' => $person->first_name,
                'middle_name' => $person->middle_name,
                'last_name' => $person->last_name,
                'date_of_birth' => optional($person->date_of_birth)?->format('Y-m-d'),
                'gender' => $person->gender,
                'strength' => $this->strength($person, $dob, $gender, $phone, $email, $father, $mother),
                'has_family' => $person->familyMembers()->whereNull('deleted_at')->exists(),
            ];
        })->values();

        return $matches->all();
    }

    private function strength(
        Person $person,
        ?string $dob,
        ?string $gender,
        string $phone,
        string $email,
        string $father,
        string $mother
    ): ?string {
        $personDob = optional($person->date_of_birth)?->format('Y-m-d');
        if ($dob && $personDob && $dob === $personDob) {
            return 'strong';
        }

        $genderOk = $gender && $person->gender && $gender === $person->gender;
        $phoneOk = $phone !== '' && $this->normalizePhone((string) $person->phone) === $phone;
        $emailOk = $email !== '' && $this->normalizeEmail((string) $person->email) === $email;
        $parentsOk = $father !== '' && $mother !== ''
            && $this->normalizeName((string) $person->father_name) === $father
            && $this->normalizeName((string) $person->mother_name) === $mother;

        if ($genderOk && ($phoneOk || $emailOk || $parentsOk)) {
            return 'medium';
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeGender(mixed $value): ?string
    {
        $gender = strtolower(trim((string) $value));

        return in_array($gender, ['male', 'female', 'other'], true) ? $gender : null;
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
