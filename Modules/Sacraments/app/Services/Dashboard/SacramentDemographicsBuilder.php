<?php

namespace Modules\Sacraments\Services\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\SacramentTypeCode;

class SacramentDemographicsBuilder
{
    /** @var list<array{key: string, label: string, min: float, max: ?float}> */
    private const AGE_BUCKETS = [
        ['key' => '0_6', 'label' => '0–6', 'min' => 0.0, 'max' => 6.99],
        ['key' => '7_9', 'label' => '7–9', 'min' => 7.0, 'max' => 9.99],
        ['key' => '10_12', 'label' => '10–12', 'min' => 10.0, 'max' => 12.99],
        ['key' => '13_17', 'label' => '13–17', 'min' => 13.0, 'max' => 17.99],
        ['key' => '18_25', 'label' => '18–25', 'min' => 18.0, 'max' => 25.99],
        ['key' => '26_35', 'label' => '26–35', 'min' => 26.0, 'max' => 35.99],
        ['key' => '36_50', 'label' => '36–50', 'min' => 36.0, 'max' => 50.99],
        ['key' => '51_plus', 'label' => '51+', 'min' => 51.0, 'max' => null],
    ];

    public function __construct(
        private readonly SacramentDashboardRecipientResolver $recipientResolver
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Builder $periodQuery): array
    {
        $records = $this->loadRecords($periodQuery);

        $genderByType = [];
        $ageByType = [];
        $overallBuckets = $this->emptyBucketCounts();

        foreach ($records as $sacrament) {
            $type = $sacrament->sacramentType;
            if (! $type) {
                continue;
            }

            $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);
            $label = (string) $type->name;

            if (! isset($genderByType[$code])) {
                $genderByType[$code] = [
                    'code' => $code,
                    'label' => $label,
                    'sacrament_type_id' => (int) $type->id,
                    'male' => 0,
                    'female' => 0,
                    'other' => 0,
                    'unknown' => 0,
                ];
            }

            if (! $this->isExcludedFromAgeDistribution($code) && ! isset($ageByType[$code])) {
                $ageByType[$code] = [
                    'code' => $code,
                    'label' => $label,
                    'sacrament_type_id' => (int) $type->id,
                    'with_age_data' => 0,
                    'average_age' => null,
                    'min_age' => null,
                    'max_age' => null,
                    'buckets' => $this->bucketRows(),
                ];
            }

            $gender = $this->recipientResolver->resolveGender($sacrament);
            $genderKey = in_array($gender, ['male', 'female', 'other', 'unknown'], true) ? $gender : 'unknown';
            $genderByType[$code][$genderKey]++;

            if ($this->isExcludedFromAgeDistribution($code)) {
                continue;
            }

            $birthDate = $this->recipientResolver->resolveBirthDate($sacrament);
            $age = $this->recipientResolver->ageAtSacrament($birthDate, $sacrament->date_administered);
            if ($age === null) {
                continue;
            }

            $ageByType[$code]['with_age_data']++;
            $ageByType[$code]['_ages'][] = $age;

            $bucketKey = $this->bucketKeyForAge($age);
            if ($bucketKey !== null) {
                foreach ($ageByType[$code]['buckets'] as &$bucket) {
                    if ($bucket['key'] === $bucketKey) {
                        $bucket['count']++;
                        break;
                    }
                }
                unset($bucket);

                foreach ($overallBuckets as &$bucket) {
                    if ($bucket['key'] === $bucketKey) {
                        $bucket['count']++;
                        break;
                    }
                }
                unset($bucket);
            }
        }

        foreach ($ageByType as $code => &$row) {
            $ages = $row['_ages'] ?? [];
            unset($row['_ages']);

            if ($ages !== []) {
                $row['average_age'] = round(array_sum($ages) / count($ages), 1);
                $row['min_age'] = round(min($ages), 1);
                $row['max_age'] = round(max($ages), 1);
            }
        }
        unset($row);

        return [
            'gender_by_type' => array_values($genderByType),
            'age_by_type' => array_values($ageByType),
            'age_buckets' => $overallBuckets,
        ];
    }

    /**
     * @return Collection<int, Sacrament>
     */
    private function loadRecords(Builder $periodQuery): Collection
    {
        return (clone $periodQuery)
            ->with([
                'sacramentType:id,name,code',
                'person:id,date_of_birth,gender',
                'participants' => fn ($query) => $query->whereNull('deleted_at'),
                'participants.familyMember:id,date_of_birth,gender,person_id',
                'participants.familyMember.person:id,date_of_birth',
                'participants.person:id,date_of_birth,gender',
            ])
            ->get();
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function bucketRows(): array
    {
        return array_map(static fn (array $bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'count' => 0,
        ], self::AGE_BUCKETS);
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function emptyBucketCounts(): array
    {
        return $this->bucketRows();
    }

    private function bucketKeyForAge(float $age): ?string
    {
        foreach (self::AGE_BUCKETS as $bucket) {
            if ($age >= $bucket['min'] && ($bucket['max'] === null || $age <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return null;
    }

    private function isExcludedFromAgeDistribution(string $code): bool
    {
        return SacramentTypeCode::isExcludedFromStandardDashboardCharts($code);
    }
}
