<?php

namespace Modules\Sacraments\Services\Dashboard;

use Illuminate\Database\Eloquent\Builder;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;
use Modules\Sacraments\Support\SacramentTypeCode;

class SacramentMatrimonyBuilder
{
    /** @var list<array{key: string, label: string, min: float, max: ?float}> */
    private const MARRIAGE_AGE_BUCKETS = [
        ['key' => '18_25', 'label' => '18–25', 'min' => 18.0, 'max' => 25.99],
        ['key' => '26_30', 'label' => '26–30', 'min' => 26.0, 'max' => 30.99],
        ['key' => '31_35', 'label' => '31–35', 'min' => 31.0, 'max' => 35.99],
        ['key' => '36_40', 'label' => '36–40', 'min' => 36.0, 'max' => 40.99],
        ['key' => '41_plus', 'label' => '41+', 'min' => 41.0, 'max' => null],
    ];

    public function __construct(
        private readonly SacramentDashboardRecipientResolver $recipientResolver
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(Builder $periodQuery, Builder $allTimeQuery, ?int $matrimonyTypeId): ?array
    {
        if ($matrimonyTypeId === null) {
            return null;
        }

        $payload = $this->buildPeriodInsights($periodQuery, $matrimonyTypeId);
        $payload['canonical_breakdown'] = $this->buildCanonicalBreakdown($allTimeQuery, $matrimonyTypeId);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodInsights(Builder $periodQuery, int $matrimonyTypeId): array
    {
        $records = (clone $periodQuery)
            ->where('sacrament_type_id', $matrimonyTypeId)
            ->with([
                'participants' => fn ($query) => $query->whereNull('deleted_at'),
                'participants.familyMember:id,date_of_birth,gender,person_id',
                'participants.familyMember.person:id,date_of_birth',
                'participants.person:id,date_of_birth,gender',
            ])
            ->get([
                'id',
                'date_administered',
                'marriage_bride_church_name',
                'marriage_groom_church_name',
                'marriage_canonical_classification',
            ]);

        if ($records->isEmpty()) {
            return $this->emptyMatrimonyPayload($matrimonyTypeId);
        }

        $brideAges = [];
        $groomAges = [];
        $brideWithAge = 0;
        $groomWithAge = 0;
        $brideMissingDob = 0;
        $groomMissingDob = 0;
        $brideBuckets = $this->indexedMarriageBuckets();
        $groomBuckets = $this->indexedMarriageBuckets();
        $parishOrigins = [];
        $interParishCount = 0;
        $canonical = $this->emptyCanonicalCounts();

        foreach ($records as $sacrament) {
            $classification = $sacrament->marriage_canonical_classification ?: 'unspecified';
            $canonical[$classification] = ($canonical[$classification] ?? 0) + 1;

            $brideParticipant = $this->participantByRole($sacrament, 'bride');
            $groomParticipant = $this->participantByRole($sacrament, 'groom');

            $brideParish = $this->resolveParishName($brideParticipant, $sacrament->marriage_bride_church_name);
            $groomParish = $this->resolveParishName($groomParticipant, $sacrament->marriage_groom_church_name);

            if ($brideParish !== null) {
                $this->incrementParishOrigin($parishOrigins, $brideParish, 'bride');
            }
            if ($groomParish !== null) {
                $this->incrementParishOrigin($parishOrigins, $groomParish, 'groom');
            }

            if ($brideParish !== null && $groomParish !== null
                && strcasecmp($brideParish, $groomParish) !== 0) {
                $interParishCount++;
            }

            $brideAge = $this->participantAge($brideParticipant, $sacrament);
            if ($brideAge !== null) {
                $brideAges[] = $brideAge;
                $brideWithAge++;
                $bucketKey = $this->marriageBucketKey($brideAge);
                if ($bucketKey !== null) {
                    $brideBuckets[$bucketKey]++;
                }
            } elseif ($brideParticipant !== null) {
                $brideMissingDob++;
            }

            $groomAge = $this->participantAge($groomParticipant, $sacrament);
            if ($groomAge !== null) {
                $groomAges[] = $groomAge;
                $groomWithAge++;
                $bucketKey = $this->marriageBucketKey($groomAge);
                if ($bucketKey !== null) {
                    $groomBuckets[$bucketKey]++;
                }
            } elseif ($groomParticipant !== null) {
                $groomMissingDob++;
            }
        }

        usort($parishOrigins, static fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'count' => $records->count(),
            'sacrament_type_id' => $matrimonyTypeId,
            'bride_avg_age' => $this->average($brideAges),
            'groom_avg_age' => $this->average($groomAges),
            'bride_min_age' => $brideAges !== [] ? round(min($brideAges), 1) : null,
            'bride_max_age' => $brideAges !== [] ? round(max($brideAges), 1) : null,
            'groom_min_age' => $groomAges !== [] ? round(min($groomAges), 1) : null,
            'groom_max_age' => $groomAges !== [] ? round(max($groomAges), 1) : null,
            'age_brackets' => [
                'bride' => $this->marriageBucketRows($brideBuckets),
                'groom' => $this->marriageBucketRows($groomBuckets),
            ],
            'parish_origins' => array_slice($parishOrigins, 0, 10),
            'inter_parish_count' => $interParishCount,
            'canonical_classification' => $canonical,
            'bride_with_age' => $brideWithAge,
            'groom_with_age' => $groomWithAge,
            'bride_missing_dob' => $brideMissingDob,
            'groom_missing_dob' => $groomMissingDob,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanonicalBreakdown(Builder $allTimeQuery, int $matrimonyTypeId): array
    {
        $records = (clone $allTimeQuery)
            ->where('sacrament_type_id', $matrimonyTypeId)
            ->with([
                'participants' => fn ($query) => $query->whereNull('deleted_at'),
            ])
            ->get([
                'id',
                'marriage_canonical_classification',
                'marriage_bride_church_name',
                'marriage_groom_church_name',
            ]);

        $total = $records->count();
        if ($total === 0) {
            return $this->emptyCanonicalBreakdown();
        }

        $catholicBoth = 0;
        $mixedDisparity = 0;
        $sameParish = 0;
        $interParish = 0;

        foreach ($records as $sacrament) {
            $classification = $sacrament->marriage_canonical_classification ?: 'unspecified';

            if ($classification === 'both_catholic') {
                $catholicBoth++;
            }

            if (in_array($classification, ['mixed_marriage', 'disparity_of_cult'], true)) {
                $mixedDisparity++;
            }

            $bride = $this->participantByRole($sacrament, 'bride');
            $groom = $this->participantByRole($sacrament, 'groom');

            $brideParish = $this->resolveParishName($bride, $sacrament->marriage_bride_church_name);
            $groomParish = $this->resolveParishName($groom, $sacrament->marriage_groom_church_name);

            if ($brideParish !== null && $groomParish !== null) {
                if (strcasecmp($brideParish, $groomParish) === 0) {
                    $sameParish++;
                } else {
                    $interParish++;
                }
            }
        }

        return [
            'total_recorded' => $total,
            'metrics' => [
                'catholic_both' => $this->metricRing($catholicBoth, $total),
                'mixed_disparity' => $this->metricRing($mixedDisparity, $total),
                'same_parish' => $this->metricRing($sameParish, $total),
                'inter_parish' => $this->metricRing($interParish, $total),
            ],
        ];
    }

    /**
     * @return array{count: int, pct: int}
     */
    private function metricRing(int $count, int $total): array
    {
        return [
            'count' => $count,
            'pct' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCanonicalBreakdown(): array
    {
        return [
            'total_recorded' => 0,
            'metrics' => [
                'catholic_both' => ['count' => 0, 'pct' => 0],
                'mixed_disparity' => ['count' => 0, 'pct' => 0],
                'same_parish' => ['count' => 0, 'pct' => 0],
                'inter_parish' => ['count' => 0, 'pct' => 0],
            ],
        ];
    }

    public function resolveMatrimonyTypeId($types): ?int
    {
        foreach ($types as $type) {
            $code = SacramentTypeCode::normalize($type->code) ?? strtoupper((string) $type->code);
            if ($code === SacramentTypeCode::MATRIMONY) {
                return (int) $type->id;
            }
        }

        return null;
    }

    private function participantByRole(Sacrament $sacrament, string $role): ?SacramentParticipant
    {
        return $sacrament->participants->firstWhere('role', $role);
    }

    private function resolveParishName(?SacramentParticipant $participant, ?string $fallback): ?string
    {
        $name = trim((string) ($participant?->affiliation_parish_name ?: $fallback ?: ''));
        if ($name === '') {
            return null;
        }

        return $name;
    }

    /**
     * @param  list<array{parish: string, role: string, count: int}>  $origins
     */
    private function incrementParishOrigin(array &$origins, string $parish, string $role): void
    {
        foreach ($origins as &$origin) {
            if ($origin['parish'] === $parish && $origin['role'] === $role) {
                $origin['count']++;

                return;
            }
        }
        unset($origin);

        $origins[] = [
            'parish' => $parish,
            'role' => $role,
            'count' => 1,
        ];
    }

    private function participantAge(?SacramentParticipant $participant, Sacrament $sacrament): ?float
    {
        if ($participant === null) {
            return null;
        }

        $birthDate = $this->recipientResolver->resolveParticipantBirthDate($participant);

        return $this->recipientResolver->ageAtSacrament($birthDate, $sacrament->date_administered);
    }

    /**
     * @param  list<float>  $ages
     */
    private function average(array $ages): ?float
    {
        if ($ages === []) {
            return null;
        }

        return round(array_sum($ages) / count($ages), 1);
    }

    /**
     * @return array<string, int>
     */
    private function indexedMarriageBuckets(): array
    {
        $indexed = [];
        foreach (self::MARRIAGE_AGE_BUCKETS as $bucket) {
            $indexed[$bucket['key']] = 0;
        }

        return $indexed;
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function emptyMarriageBuckets(): array
    {
        return $this->marriageBucketRows($this->indexedMarriageBuckets());
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, count: int}>
     */
    private function marriageBucketRows(array $counts): array
    {
        return array_map(static fn (array $bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'count' => $counts[$bucket['key']] ?? 0,
        ], self::MARRIAGE_AGE_BUCKETS);
    }

    private function marriageBucketKey(float $age): ?string
    {
        foreach (self::MARRIAGE_AGE_BUCKETS as $bucket) {
            if ($age >= $bucket['min'] && ($bucket['max'] === null || $age <= $bucket['max'])) {
                return $bucket['key'];
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function emptyCanonicalCounts(): array
    {
        return [
            'both_catholic' => 0,
            'mixed_marriage' => 0,
            'disparity_of_cult' => 0,
            'other' => 0,
            'unspecified' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMatrimonyPayload(int $matrimonyTypeId): array
    {
        return [
            'count' => 0,
            'sacrament_type_id' => $matrimonyTypeId,
            'bride_avg_age' => null,
            'groom_avg_age' => null,
            'bride_min_age' => null,
            'bride_max_age' => null,
            'groom_min_age' => null,
            'groom_max_age' => null,
            'age_brackets' => [
                'bride' => $this->emptyMarriageBuckets(),
                'groom' => $this->emptyMarriageBuckets(),
            ],
            'parish_origins' => [],
            'inter_parish_count' => 0,
            'canonical_classification' => $this->emptyCanonicalCounts(),
            'bride_with_age' => 0,
            'groom_with_age' => 0,
            'bride_missing_dob' => 0,
            'groom_missing_dob' => 0,
        ];
    }
}
