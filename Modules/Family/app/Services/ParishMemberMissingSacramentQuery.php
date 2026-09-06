<?php

namespace Modules\Family\Services;

use Modules\Family\Support\ParishProgressionFilter;

/**
 * Families with at least one eligible active member missing a sacrament.
 */
class ParishMemberMissingSacramentQuery
{
    private const EUCHARIST_MIN_AGE = 7;

    private const PROGRESSION_MIN_AGE = 10;

    private const CONFIRMATION_MIN_AGE = 10;

    private const MATRIMONY_MIN_AGE = 18;

    /** Exclusive: female marriage-eligibility cohort is age > 18. */
    private const FEMALE_UNMARRIED_AGE_THRESHOLD = 18;

    /** Exclusive: male marriage-eligibility cohort is age > 23. */
    private const MALE_UNMARRIED_AGE_THRESHOLD = 23;

    public function __construct(
        private readonly ParishMemberSacramentEligibilityQuery $memberQuery,
        private readonly SacramentReceiptIndexBuilder $receiptIndexBuilder,
    ) {}

    /**
     * @param  list<array<string, mixed>>|null  $members
     * @param  array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>|null  $receiptIndex
     * @return array<string, array{count: int, eligible_count: int}>
     */
    public function progressionMetrics(
        int|string $tenantId,
        ?string $bccId = null,
        ?array $members = null,
        ?array $receiptIndex = null,
    ): array {
        $members ??= $this->memberQuery->eligibleMembers((int) $tenantId, $bccId);
        $receiptIndex ??= $this->receiptIndexBuilder->build($tenantId);

        $withoutCommunion = 0;
        $withoutCommunionEligible = 0;
        $withoutConfirmation = 0;
        $withoutConfirmationEligible = 0;
        $femaleUnmarried = 0;
        $femaleUnmarriedEligible = 0;
        $maleUnmarried = 0;
        $maleUnmarriedEligible = 0;

        foreach ($members as $member) {
            if ($this->isFemaleMarriageAgeEligible($member)
                && ! $this->memberHasSacrament($member, 'HOLY_ORDERS', $receiptIndex)
            ) {
                $femaleUnmarriedEligible++;
                if ($this->matchesUnmarriedMarriageCohort($member, 'female', self::FEMALE_UNMARRIED_AGE_THRESHOLD, $receiptIndex)) {
                    $femaleUnmarried++;
                }
            }

            if ($this->isMaleMarriageAgeEligible($member)
                && ! $this->memberHasSacrament($member, 'HOLY_ORDERS', $receiptIndex)
            ) {
                $maleUnmarriedEligible++;
                if ($this->matchesUnmarriedMarriageCohort($member, 'male', self::MALE_UNMARRIED_AGE_THRESHOLD, $receiptIndex)) {
                    $maleUnmarried++;
                }
            }

            if ($member['age'] === null || $member['age'] < self::PROGRESSION_MIN_AGE) {
                continue;
            }

            if (! $this->memberHasSacrament($member, 'BAPTISM', $receiptIndex)) {
                continue;
            }

            $withoutCommunionEligible++;
            if (! $this->memberHasSacrament($member, 'EUCHARIST', $receiptIndex)) {
                $withoutCommunion++;
            }

            $withoutConfirmationEligible++;
            if (! $this->memberHasSacrament($member, 'CONFIRMATION', $receiptIndex)) {
                $withoutConfirmation++;
            }
        }

        return [
            'baptized_without_communion' => [
                'count' => $withoutCommunion,
                'eligible_count' => $withoutCommunionEligible,
            ],
            'baptized_without_confirmation' => [
                'count' => $withoutConfirmation,
                'eligible_count' => $withoutConfirmationEligible,
            ],
            ParishProgressionFilter::FEMALE_UNMARRIED_OVER_18 => [
                'count' => $femaleUnmarried,
                'eligible_count' => $femaleUnmarriedEligible,
            ],
            ParishProgressionFilter::MALE_UNMARRIED_OVER_23 => [
                'count' => $maleUnmarried,
                'eligible_count' => $maleUnmarriedEligible,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function memberIdsForProgression(int|string $tenantId, string $progression, ?string $bccId = null): array
    {
        $members = $this->memberQuery->eligibleMembers((int) $tenantId, $bccId);
        $receiptIndex = $this->receiptIndexBuilder->build($tenantId);

        $memberIds = [];
        foreach ($members as $member) {
            if (! $this->matchesProgression($member, $progression, $receiptIndex)) {
                continue;
            }

            $memberIds[] = $member['id'];
        }

        return $memberIds;
    }

    /**
     * @return list<string>
     */
    public function familyIdsForMissingSacrament(int|string $tenantId, string $sacramentCode, ?string $bccId = null): array
    {
        $code = $this->receiptIndexBuilder->normalizeCode($sacramentCode);
        $members = $this->memberQuery->eligibleMembers((int) $tenantId, $bccId);
        $receiptIndex = $this->receiptIndexBuilder->build($tenantId);

        $familyIds = [];
        foreach ($members as $member) {
            if (! $this->isEligibleForSacrament($member, $code)) {
                continue;
            }

            if ($this->memberHasSacrament($member, $code, $receiptIndex)) {
                continue;
            }

            if (! empty($member['family_id'])) {
                $familyIds[$member['family_id']] = true;
            }
        }

        return array_keys($familyIds);
    }

    /**
     * @return list<string>
     */
    public function familyIdsForProgression(int|string $tenantId, string $progression, ?string $bccId = null): array
    {
        $members = $this->memberQuery->eligibleMembers((int) $tenantId, $bccId);
        $receiptIndex = $this->receiptIndexBuilder->build($tenantId);

        $familyIds = [];
        foreach ($members as $member) {
            if (! $this->matchesProgression($member, $progression, $receiptIndex)) {
                continue;
            }

            if (! empty($member['family_id'])) {
                $familyIds[$member['family_id']] = true;
            }
        }

        return array_keys($familyIds);
    }

    /**
     * @param  array{id: string, person_id: ?string, family_id: ?string, age: ?int, gender: ?string, marital_status: ?string, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     * @param  array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>  $receiptIndex
     */
    private function matchesProgression(array $member, string $progression, array $receiptIndex): bool
    {
        $normalized = ParishProgressionFilter::normalize($progression);
        if ($normalized === null) {
            return false;
        }

        return match ($normalized) {
            ParishProgressionFilter::BAPTIZED_WITHOUT_COMMUNION => $member['age'] !== null
                && $member['age'] >= self::PROGRESSION_MIN_AGE
                && $this->memberHasSacrament($member, 'BAPTISM', $receiptIndex)
                && ! $this->memberHasSacrament($member, 'EUCHARIST', $receiptIndex),
            ParishProgressionFilter::BAPTIZED_WITHOUT_CONFIRMATION => $member['age'] !== null
                && $member['age'] >= self::PROGRESSION_MIN_AGE
                && $this->memberHasSacrament($member, 'BAPTISM', $receiptIndex)
                && ! $this->memberHasSacrament($member, 'CONFIRMATION', $receiptIndex),
            ParishProgressionFilter::FEMALE_UNMARRIED_OVER_18 => $this->matchesUnmarriedMarriageCohort(
                $member,
                'female',
                self::FEMALE_UNMARRIED_AGE_THRESHOLD,
                $receiptIndex
            ),
            ParishProgressionFilter::MALE_UNMARRIED_OVER_23 => $this->matchesUnmarriedMarriageCohort(
                $member,
                'male',
                self::MALE_UNMARRIED_AGE_THRESHOLD,
                $receiptIndex
            ),
            default => false,
        };
    }

    /**
     * @param  array{id: string, person_id: ?string, family_id: ?string, age: ?int, gender: ?string, marital_status: ?string, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     * @param  array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>  $receiptIndex
     */
    private function matchesUnmarriedMarriageCohort(
        array $member,
        string $gender,
        int $exclusiveAgeThreshold,
        array $receiptIndex
    ): bool {
        if (($member['gender'] ?? null) !== $gender) {
            return false;
        }

        if ($member['age'] === null || $member['age'] <= $exclusiveAgeThreshold) {
            return false;
        }

        if (($member['marital_status'] ?? null) !== 'single') {
            return false;
        }

        if (! empty($member['marriage_date'])) {
            return false;
        }

        return ! $this->memberHasSacrament($member, 'HOLY_ORDERS', $receiptIndex);
    }

    /**
     * @param  array{age: ?int, gender: ?string}  $member
     */
    private function isFemaleMarriageAgeEligible(array $member): bool
    {
        return ($member['gender'] ?? null) === 'female'
            && $member['age'] !== null
            && $member['age'] > self::FEMALE_UNMARRIED_AGE_THRESHOLD;
    }

    /**
     * @param  array{age: ?int, gender: ?string}  $member
     */
    private function isMaleMarriageAgeEligible(array $member): bool
    {
        return ($member['gender'] ?? null) === 'male'
            && $member['age'] !== null
            && $member['age'] > self::MALE_UNMARRIED_AGE_THRESHOLD;
    }

    /**
     * @param  array{id: string, person_id: ?string, family_id: ?string, age: ?int, gender: ?string, marital_status: ?string, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     * @param  array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>  $receiptIndex
     */
    private function memberHasSacrament(array $member, string $code, array $receiptIndex): bool
    {
        $memberField = match ($code) {
            'BAPTISM' => 'baptism_date',
            'EUCHARIST' => 'first_communion_date',
            'CONFIRMATION' => 'confirmation_date',
            'MATRIMONY' => 'marriage_date',
            default => null,
        };

        if ($memberField !== null && ! empty($member[$memberField])) {
            return true;
        }

        $receipts = $receiptIndex[$code] ?? ['person_ids' => [], 'member_ids' => []];

        if ($member['person_id'] !== null && isset($receipts['person_ids'][$member['person_id']])) {
            return true;
        }

        return isset($receipts['member_ids'][$member['id']]);
    }

    /**
     * @param  array{id: string, person_id: ?string, family_id: ?string, age: ?int, gender: ?string, marital_status: ?string, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     */
    private function isEligibleForSacrament(array $member, string $code): bool
    {
        return match ($code) {
            'BAPTISM' => true,
            'EUCHARIST' => $member['age'] !== null && $member['age'] >= self::EUCHARIST_MIN_AGE,
            'CONFIRMATION' => $member['age'] !== null && $member['age'] >= self::CONFIRMATION_MIN_AGE,
            'MATRIMONY' => $member['age'] !== null && $member['age'] >= self::MATRIMONY_MIN_AGE,
            default => false,
        };
    }
}
