<?php

namespace Modules\Sacraments\Services\Dashboard;

use Illuminate\Support\Collection;
use Modules\Family\Services\ParishMemberMissingSacramentQuery;
use Modules\Family\Services\ParishMemberSacramentEligibilityQuery;
use Modules\Family\Services\SacramentReceiptIndexBuilder;
use Modules\Sacraments\Support\SacramentTypeCode;

class SacramentGapAnalysisBuilder
{
    private const EUCHARIST_MIN_AGE = 7;

    private const PROGRESSION_MIN_AGE = 10;

    private const CONFIRMATION_MIN_AGE = 10;

    private const MATRIMONY_MIN_AGE = 18;

    /** @var list<array{key: string, label: string, min: int, max: ?int}> */
    private const MISSING_AGE_BUCKETS = [
        ['key' => '0_6', 'label' => '0–6', 'min' => 0, 'max' => 6],
        ['key' => '7_9', 'label' => '7–9', 'min' => 7, 'max' => 9],
        ['key' => '10_12', 'label' => '10–12', 'min' => 10, 'max' => 12],
        ['key' => '13_17', 'label' => '13–17', 'min' => 13, 'max' => 17],
        ['key' => '18_25', 'label' => '18–25', 'min' => 18, 'max' => 25],
        ['key' => '26_35', 'label' => '26–35', 'min' => 26, 'max' => 35],
        ['key' => '36_50', 'label' => '36–50', 'min' => 36, 'max' => 50],
        ['key' => '51_plus', 'label' => '51+', 'min' => 51, 'max' => null],
    ];

    public function __construct(
        private readonly ParishMemberSacramentEligibilityQuery $memberQuery,
        private readonly ParishMemberMissingSacramentQuery $progressionQuery,
        private readonly SacramentReceiptIndexBuilder $receiptIndexBuilder,
    ) {}

    /**
     * @param  Collection<int, SacramentType>  $types
     * @param  list<string>  $restrictedTypeCodes
     * @return array<string, mixed>
     */
    public function build(
        int $tenantId,
        $types,
        array $restrictedTypeCodes,
        ?string $bccId = null,
        bool $includeMarriageGaps = false
    ): array {
        $members = $this->memberQuery->eligibleMembers($tenantId, $bccId);
        $receiptIndex = $this->receiptIndexBuilder->build($tenantId, $restrictedTypeCodes);
        $trackedTypes = $this->trackedSacramentTypes($types, $includeMarriageGaps);

        $bySacrament = [];
        foreach ($trackedTypes as $type) {
            $code = $type['code'];
            $eligible = 0;
            $received = 0;
            $missingBuckets = $this->emptyMissingBuckets();

            foreach ($members as $member) {
                if (! $this->isEligibleForSacrament($member, $code)) {
                    continue;
                }

                $eligible++;
                if ($this->memberHasSacrament($member, $code, $receiptIndex)) {
                    $received++;

                    continue;
                }

                $bucketKey = $this->missingBucketKey($member['age']);
                if ($bucketKey !== null) {
                    foreach ($missingBuckets as &$bucket) {
                        if ($bucket['key'] === $bucketKey) {
                            $bucket['count']++;
                            break;
                        }
                    }
                    unset($bucket);
                }
            }

            $missing = max(0, $eligible - $received);
            $bySacrament[] = [
                'code' => $code,
                'label' => $type['label'],
                'sacrament_type_id' => $type['sacrament_type_id'],
                'eligible_count' => $eligible,
                'received_count' => $received,
                'missing_count' => $missing,
                'participation_pct' => $this->participationPct($received, $eligible),
                'missing_age_buckets' => $missingBuckets,
            ];
        }

        return [
            'eligible_members' => count($members),
            'thresholds' => [
                'eucharist' => self::EUCHARIST_MIN_AGE,
                'progression' => self::PROGRESSION_MIN_AGE,
                'confirmation' => self::CONFIRMATION_MIN_AGE,
                'matrimony' => self::MATRIMONY_MIN_AGE,
            ],
            'by_sacrament' => $bySacrament,
            'progression' => $this->progressionQuery->progressionMetrics($tenantId, $bccId, $members, $receiptIndex),
        ];
    }

    /**
     * @param  Collection<int, SacramentType>  $types
     * @return list<array{code: string, label: string, sacrament_type_id: int}>
     */
    private function trackedSacramentTypes($types, bool $includeMarriageGaps): array
    {
        $codes = [
            SacramentTypeCode::BAPTISM,
            SacramentTypeCode::EUCHARIST,
            SacramentTypeCode::CONFIRMATION,
        ];

        if ($includeMarriageGaps) {
            $codes[] = SacramentTypeCode::MATRIMONY;
        }

        $rows = [];
        foreach ($types as $type) {
            $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);
            if (! in_array($code, $codes, true)) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'label' => (string) $type->name,
                'sacrament_type_id' => (int) $type->id,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{id: string, person_id: ?string, age: ?int, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     * @param  array<string, array{person_ids: array<string, bool>, member_ids: array<string, bool>}>  $receiptIndex
     */
    private function memberHasSacrament(array $member, string $code, array $receiptIndex): bool
    {
        $memberField = match ($code) {
            SacramentTypeCode::BAPTISM => 'baptism_date',
            SacramentTypeCode::EUCHARIST => 'first_communion_date',
            SacramentTypeCode::CONFIRMATION => 'confirmation_date',
            SacramentTypeCode::MATRIMONY => 'marriage_date',
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
     * @param  array{id: string, person_id: ?string, age: ?int, baptism_date: ?string, first_communion_date: ?string, confirmation_date: ?string, marriage_date: ?string}  $member
     */
    private function isEligibleForSacrament(array $member, string $code): bool
    {
        return match ($code) {
            SacramentTypeCode::BAPTISM => true,
            SacramentTypeCode::EUCHARIST => $member['age'] !== null && $member['age'] >= self::EUCHARIST_MIN_AGE,
            SacramentTypeCode::CONFIRMATION => $member['age'] !== null && $member['age'] >= self::CONFIRMATION_MIN_AGE,
            SacramentTypeCode::MATRIMONY => $member['age'] !== null && $member['age'] >= self::MATRIMONY_MIN_AGE,
            default => false,
        };
    }

    private function participationPct(int $received, int $eligible): float
    {
        if ($eligible <= 0) {
            return 0.0;
        }

        return round(($received / $eligible) * 100, 1);
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function emptyMissingBuckets(): array
    {
        return array_map(static fn (array $bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'count' => 0,
        ], self::MISSING_AGE_BUCKETS);
    }

    private function missingBucketKey(?int $age): ?string
    {
        if ($age === null) {
            return null;
        }

        foreach (self::MISSING_AGE_BUCKETS as $bucket) {
            if ($age >= $bucket['min'] && ($bucket['max'] === null || $age <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return null;
    }
}
