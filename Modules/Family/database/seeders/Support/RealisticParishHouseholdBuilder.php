<?php

namespace Modules\Family\Database\Seeders\Support;

use Carbon\Carbon;
use Faker\Factory as FakerFactory;
use Faker\Generator;

/**
 * Builds tenant-scoped family + member payloads with coherent demographics and sacraments.
 */
class RealisticParishHouseholdBuilder
{
    public const MARKER = 'bcc_dummy_families_v1';

    private Generator $faker;

    /** @var array<int, string> */
    private array $usedPhones = [];

    /** @var array<int, string> */
    private array $usedEmails = [];

    public function __construct(
        private readonly string $parishName,
        int $seed,
    ) {
        $this->faker = FakerFactory::create('en_IN');
        $this->faker->seed($seed);
    }

    public function registerExistingPhone(string $phone): void
    {
        $this->usedPhones[$this->normalizePhone($phone)] = true;
    }

    public function registerExistingEmail(string $email): void
    {
        $this->usedEmails[strtolower($email)] = true;
    }

    /**
     * @return array{family: array<string, mixed>, members: list<array<string, mixed>>}
     */
    public function build(
        string $bccId,
        string $bccSlug,
        int $familyIndex,
        ?int $countryId,
        ?int $stateId,
        string $defaultCity,
    ): array {
        $this->faker->seed(crc32($bccId) ^ ($familyIndex * 9973));

        $surname = $this->pickSurname();
        $archetype = $familyIndex % 6;
        $memberCount = 4 + ($familyIndex % 3); // 4–6

        $streetNo = 10 + ($familyIndex % 180);
        $addressLine1 = sprintf('%d %s', $streetNo, $this->faker->randomElement([
            'MG Road', 'Church Lane', 'BCC Colony Road', 'St. Mary Street', 'Parish Avenue', 'Hill View Road',
        ]));
        $city = $defaultCity !== '' ? $defaultCity : $this->faker->city();
        $postal = sprintf('%06d', 682000 + ($familyIndex % 500));

        $headMale = $archetype !== 3;
        $head = $this->makeAdult($surname, $headMale ? 'male' : 'female', $headMale, 32, 58);
        $head['relationship_to_head'] = 'self';
        $head['is_primary_contact'] = true;
        $head['marital_status'] = $archetype === 3 ? 'widowed' : 'married';

        $members = [$head];
        $spouse = null;
        $headParent = null;

        if ($archetype !== 3) {
            $spouse = $this->makeAdult($surname, $headMale ? 'female' : 'male', false, 30, 56);
            $spouse['relationship_to_head'] = 'spouse';
            $spouse['marital_status'] = 'married';
            $this->applyMarriageSacraments($head, $spouse, $surname);
            $members[] = $spouse;
        }

        $extraSlots = $memberCount - count($members);
        $childrenToAdd = min($extraSlots, 4);
        $remaining = $extraSlots - $childrenToAdd;

        if ($remaining > 0 && in_array($archetype, [2, 4], true)) {
            $elderGender = $headMale ? 'male' : 'female';
            $relation = $elderGender === 'male' ? 'father' : 'mother';
            $headParent = $this->makeAdult($surname, $elderGender, false, 62, 84);
            $headParent['relationship_to_head'] = $relation;
            $headParent['marital_status'] = $this->faker->randomElement(['married', 'widowed']);
            $members[] = $headParent;
            $remaining--;
        }

        if ($remaining > 0 && $archetype === 5) {
            $grand = $this->makeAdult($surname, $this->faker->randomElement(['male', 'female']), false, 68, 82);
            $grand['relationship_to_head'] = $this->faker->randomElement(['grandfather', 'grandmother']);
            $grand['marital_status'] = 'widowed';
            $members[] = $grand;
            $remaining--;
        }

        for ($i = 0; $i < $childrenToAdd; $i++) {
            $child = $this->makeChild($surname, $i);
            $members[] = $child;
        }

        while (count($members) < $memberCount) {
            $members[] = $this->makeChild($surname, count($members));
        }

        $familyName = $surname.' Family';
        $headDisplay = trim($head['first_name'].' '.($head['middle_name'] ? $head['middle_name'].' ' : '').$head['last_name']);

        $family = [
            'family_name' => $familyName,
            'head_of_family' => $headDisplay,
            'address_line_1' => $addressLine1,
            'address_line_2' => $this->faker->optional(0.25)->buildingNumber(),
            'city' => $city,
            'state_id' => $stateId,
            'country_id' => $countryId,
            'postal_code' => $postal,
            'bcc_id' => $bccId,
            'status' => 'active',
            'notes' => self::MARKER.' | BCC '.$bccSlug.' household #'.($familyIndex + 1),
            'allow_duplicate' => true,
        ];

        return [
            'family' => $family,
            'members' => array_slice($members, 0, $memberCount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAdult(string $surname, string $gender, bool $isHead, int $minAge, int $maxAge): array
    {
        $age = $this->faker->numberBetween($minAge, $maxAge);
        $dob = now()->subYears($age)->subDays($this->faker->numberBetween(0, 364))->format('Y-m-d');
        $first = $gender === 'male' ? $this->pickMaleName() : $this->pickFemaleName();
        $middle = $this->faker->optional(0.4)->passthrough($this->pickMaleName());

        $member = [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $surname,
            'date_of_birth' => $dob,
            'gender' => $gender,
            'phone' => $this->uniquePhone(),
            'email' => $this->uniqueEmail($first, $surname),
            'occupation' => $this->occupationForAge($age, $gender),
            'education' => $this->educationForAge($age),
            'skills_talents' => $this->faker->optional(0.35)->randomElement([
                'Choir singing', 'Catechism volunteer', 'Youth ministry', 'Parish finance committee',
                'SVP outreach', 'Altar serving coordinator', 'Bible study facilitator',
            ]),
            'status' => 'active',
        ];

        $this->applyLifecycleSacraments($member, $age, false);

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeChild(string $surname, int $childIndex): array
    {
        $gender = $this->faker->randomElement(['male', 'female']);
        $age = match ($childIndex % 4) {
            0 => $this->faker->numberBetween(2, 8),
            1 => $this->faker->numberBetween(9, 15),
            2 => $this->faker->numberBetween(16, 22),
            default => $this->faker->numberBetween(1, 17),
        };
        $dob = now()->subYears($age)->subDays($this->faker->numberBetween(0, 300))->format('Y-m-d');
        $first = $gender === 'male' ? $this->pickMaleName() : $this->pickFemaleName();

        $member = [
            'first_name' => $first,
            'middle_name' => $this->faker->optional(0.3)->passthrough($this->pickMaleName()),
            'last_name' => $surname,
            'date_of_birth' => $dob,
            'gender' => $gender,
            'relationship_to_head' => $gender === 'male' ? 'son' : 'daughter',
            'marital_status' => $age >= 18 ? $this->faker->randomElement(['single', 'single', 'married']) : 'single',
            'phone' => $age >= 16 ? $this->uniquePhone() : null,
            'email' => $age >= 16 ? $this->uniqueEmail($first, $surname) : null,
            'occupation' => $age >= 18 ? $this->occupationForAge($age, $gender) : ($age >= 14 ? 'Student' : null),
            'education' => $this->educationForAge($age),
            'status' => 'active',
        ];

        $this->applyLifecycleSacraments($member, $age, ($member['marital_status'] ?? '') === 'married');

        return $member;
    }

    /**
     * @param  array<string, mixed>  $head
     * @param  array<string, mixed>  $spouse
     */
    private function applyMarriageSacraments(array &$head, array &$spouse, string $surname): void
    {
        $headAge = $this->ageFromDob((string) $head['date_of_birth']);
        $spouseAge = $this->ageFromDob((string) $spouse['date_of_birth']);
        $marriageAge = max(22, min($headAge, $spouseAge) - $this->faker->numberBetween(0, 5));
        $marriageDate = now()->subYears($headAge - $marriageAge)->format('Y-m-d');

        if ($marriageDate >= now()->format('Y-m-d')) {
            return;
        }

        $place = $this->parishName;
        $headName = trim($head['first_name'].' '.$head['last_name']);
        $spouseName = trim($spouse['first_name'].' '.$spouse['last_name']);

        foreach ([&$head, &$spouse] as $idx => &$person) {
            $person['marriage_date'] = $marriageDate;
            $person['marriage_place'] = $place;
            $person['marriage_spouse_name'] = $idx === 0 ? $spouseName : $headName;
            $this->fillMarriageRegisterFields($person, $head, $spouse, $surname, $idx === 0);
        }
    }

    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $groom
     * @param  array<string, mixed>  $bride
     */
    private function fillMarriageRegisterFields(array &$person, array $groom, array $bride, string $surname, bool $personIsGroom): void
    {
        $groomFull = trim($groom['first_name'].' '.($groom['middle_name'] ? $groom['middle_name'].' ' : '').$groom['last_name']);
        $brideFull = trim($bride['first_name'].' '.($bride['middle_name'] ? $bride['middle_name'].' ' : '').$bride['last_name']);

        $person['marriage_groom_full_name'] = $groomFull;
        $person['marriage_bride_full_name'] = $brideFull;
        $person['marriage_groom_father_name'] = $groom['first_name'].' '.$surname.' Sr';
        $person['marriage_groom_mother_name'] = 'Mrs. '.$surname;
        $person['marriage_bride_father_name'] = $bride['first_name'].' '.$surname.' Sr';
        $person['marriage_bride_mother_name'] = 'Mrs. '.$surname;
        $person['marriage_groom_church_type'] = 'home_parish';
        $person['marriage_bride_church_type'] = 'home_parish';
        $person['marriage_groom_church_name'] = $this->parishName;
        $person['marriage_bride_church_name'] = $this->parishName;
        $person['marriage_place'] = $this->parishName;

        if ($personIsGroom) {
            $person['marriage_groom_address'] = 'Parish register — '.$surname.' family residence';
        } else {
            $person['marriage_bride_address'] = 'Parish register — '.$surname.' family residence';
        }
    }

    /**
     * @param  array<string, mixed>  $member
     */
    private function applyLifecycleSacraments(array &$member, int $age, bool $includeMarriage): void
    {
        $dob = Carbon::parse($member['date_of_birth']);
        $baptism = $dob->copy()->addDays($this->faker->numberBetween(14, 120));
        if ($baptism->isFuture()) {
            return;
        }

        $member['baptism_date'] = $baptism->format('Y-m-d');
        $member['baptism_place'] = $this->parishName;
        $member['baptism_location_type'] = 'home_parish';
        $member['baptism_church_name'] = $this->parishName;
        $member['baptism_church_address'] = $this->parishName.' parish campus';
        $member['baptism_priest_name'] = 'Fr. '.$this->faker->lastName();
        $member['baptism_priest_is_home'] = true;
        $member['baptism_godparent_primary'] = $this->faker->name();
        $member['baptism_godparent_secondary'] = $this->faker->name();

        if ($age >= 8) {
            $fc = $dob->copy()->addYears(8)->addMonths($this->faker->numberBetween(0, 18));
            if ($fc->lte(now())) {
                $member['first_communion_date'] = $fc->format('Y-m-d');
                $member['first_communion_place'] = $this->parishName;
            }
        }

        if ($age >= 14) {
            $conf = $dob->copy()->addYears(15)->addMonths($this->faker->numberBetween(0, 12));
            if ($conf->lte(now()) && ! empty($member['first_communion_date'])) {
                $member['confirmation_date'] = $conf->format('Y-m-d');
                $member['confirmation_place'] = $this->parishName;
            }
        }

        if ($includeMarriage && $age >= 20 && empty($member['marriage_date'])) {
            $md = $dob->copy()->addYears($this->faker->numberBetween(22, min(30, $age)))->addMonths($this->faker->numberBetween(0, 10));
            if ($md->lte(now())) {
                $member['marriage_date'] = $md->format('Y-m-d');
                $member['marriage_place'] = $this->parishName;
                $member['marriage_spouse_name'] = $this->faker->firstName().' '.$member['last_name'];
            }
        }
    }

    private function ageFromDob(string $dob): int
    {
        return (int) Carbon::parse($dob)->diffInYears(now());
    }

    private function uniquePhone(): string
    {
        for ($attempt = 0; $attempt < 50_000; $attempt++) {
            $digits = '9'.str_pad((string) $this->faker->numberBetween(0, 999999999), 9, '0', STR_PAD_LEFT);
            $phone = '+91 '.$digits;
            $key = $this->normalizePhone($phone);
            if (! isset($this->usedPhones[$key])) {
                $this->usedPhones[$key] = true;

                return $phone;
            }
        }

        throw new \RuntimeException('Unable to allocate unique phone for dummy household.');
    }

    private function uniqueEmail(string $first, string $surname): string
    {
        $base = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $first.$surname) ?: 'member');
        for ($attempt = 0; $attempt < 50_000; $attempt++) {
            $email = sprintf('%s.%d@dummy.sacredheart.local', $base, $this->faker->unique()->numberBetween(10000, 99999999));
            if (! isset($this->usedEmails[$email])) {
                $this->usedEmails[$email] = true;

                return $email;
            }
        }

        throw new \RuntimeException('Unable to allocate unique email for dummy household.');
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }

    private function pickSurname(): string
    {
        return $this->faker->randomElement([
            'Fernandes', 'Dias', 'Pereira', 'Rodrigues', 'Menezes', 'Lobo', 'Pinto', 'D\'Cruz',
            'Thomas', 'Joseph', 'Mathew', 'Varghese', 'Kurian', 'Chacko', 'Ninan', 'George',
            'Antony', 'Sebastian', 'Francis', 'Xavier', 'Mendes', 'Costa', 'Gomes', 'Martins',
        ]);
    }

    private function pickMaleName(): string
    {
        return $this->faker->randomElement([
            'Antony', 'Francis', 'Joseph', 'Thomas', 'John', 'Michael', 'George', 'Paul',
            'Mathew', 'Philip', 'Stephen', 'Rajan', 'Sunil', 'Vincent', 'Jacob', 'Alexander',
        ]);
    }

    private function pickFemaleName(): string
    {
        return $this->faker->randomElement([
            'Mary', 'Mariam', 'Theresa', 'Grace', 'Elizabeth', 'Anna', 'Cecilia', 'Rosa',
            'Linda', 'Sneha', 'Deepa', 'Merin', 'Sandra', 'Jessy', 'Lissy', 'Alphonsa',
        ]);
    }

    private function occupationForAge(int $age, string $gender): ?string
    {
        if ($age < 18) {
            return 'Student';
        }

        return $this->faker->randomElement([
            'School teacher', 'Nurse', 'Accountant', 'Software engineer', 'Shop owner',
            'Auto driver', 'Bank clerk', 'Electrician', 'Government servant', 'Homemaker',
            'Pharmacist', 'Civil engineer', 'Fisherman', 'Tailor',
        ]);
    }

    private function educationForAge(int $age): ?string
    {
        if ($age < 17) {
            return null;
        }

        return $this->faker->randomElement([
            'Plus Two', 'B.Com', 'B.Tech', 'Diploma', 'B.Ed', 'M.Com', 'ITI', 'BA',
        ]);
    }
}
