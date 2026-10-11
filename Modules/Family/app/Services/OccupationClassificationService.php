<?php

namespace Modules\Family\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Modules\Family\Support\FamilyQueryFilters;

/**
 * Executive occupation groups for family_members.occupation.
 *
 * The column stays free text and is never rewritten. Each member is assigned
 * exactly one category. Government, retirement, and student status win over
 * the job title when the text states them. Rules live only in this class.
 *
 * Documented values that stay Other / Unclassified:
 * - accountant — not Chartered Accountant, and no government/private/self-employed sector
 * - school teacher — no government, private, or retired status
 * - bank clerk — bank clerical work without a public-sector or private-sector marker
 * - parish secretary, catechist, and other church or parish roles — not a separate executive category
 */
final class OccupationClassificationService
{
    public const NOT_RECORDED = 'not_recorded';

    public const UNCLASSIFIED = 'unclassified';

    /**
     * Stable identifiers in executive display order.
     * Zero-count categories stay in this catalog and are omitted from the graph.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'student' => 'Student',
        'government_job' => 'Government Job',
        'private_job' => 'Private Job',
        'professional' => 'Professional',
        'labour_skilled_trade' => 'Labour / Skilled Trade',
        'business_self_employed' => 'Business / Self-Employed',
        'former_retired' => 'Former / Retired',
        'homemaker' => 'Homemaker',
        self::UNCLASSIFIED => 'Other / Unclassified',
        self::NOT_RECORDED => 'Not Recorded',
    ];

    /**
     * @var array<string, string>
     */
    private const REASONS = [
        'student' => 'Current student status.',
        'government_job' => 'Government or public-sector employment is explicit.',
        'private_job' => 'Private-sector employment without a recognized professional title.',
        'professional' => 'Recognized professional occupation without explicit government employment.',
        'labour_skilled_trade' => 'Manual, industrial, or skilled-trade occupation.',
        'business_self_employed' => 'Business ownership or self-employment is explicit.',
        'former_retired' => 'Retired or former employment status takes precedence over the previous occupation.',
        'homemaker' => 'Homemaker status.',
        self::UNCLASSIFIED => 'Occupation is present but cannot be classified confidently.',
        self::NOT_RECORDED => 'No occupation is recorded.',
    ];

    /**
     * Whole-value exceptions. These are real occupations whose sector is not in the text.
     *
     * @var array<string, string>
     */
    private const AMBIGUOUS = [
        'accountant' => 'Accountant without Chartered Accountant status or an employment sector.',
        'school teacher' => 'School teacher without government, private, or retired status.',
        'bank clerk' => 'Bank clerk without public-sector or private-sector identification.',
        'parish secretary' => 'Church or parish service is included in Other / Unclassified.',
        'catechist' => 'Church or parish service is included in Other / Unclassified.',
    ];

    public static function classify(?string $value): string
    {
        $normalized = FamilyQueryFilters::normalizeLabel((string) $value);
        if ($normalized === '') {
            return self::NOT_RECORDED;
        }

        return self::classifyNormalized($normalized);
    }

    public static function classifyNormalized(string $normalized): string
    {
        $normalized = FamilyQueryFilters::normalizeLabel($normalized);
        if ($normalized === '') {
            return self::NOT_RECORDED;
        }

        if (self::isStudent($normalized)) {
            return 'student';
        }
        if (self::isFormerOrRetired($normalized)) {
            return 'former_retired';
        }
        if (self::isGovernment($normalized)) {
            return 'government_job';
        }
        if (self::isBusiness($normalized)) {
            return 'business_self_employed';
        }
        if (self::isProfessional($normalized)) {
            return 'professional';
        }
        if (self::isLabour($normalized)) {
            return 'labour_skilled_trade';
        }
        if (self::isPrivateJob($normalized)) {
            return 'private_job';
        }
        if (self::isHomemaker($normalized)) {
            return 'homemaker';
        }

        return self::UNCLASSIFIED;
    }

    public static function labelFor(string $category): string
    {
        return self::CATEGORIES[$category] ?? $category;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return array{raw: ?string, normalized: string, category: string, label: string, reason: string}
     */
    public static function explain(?string $value): array
    {
        $normalized = FamilyQueryFilters::normalizeLabel((string) $value);
        $category = self::classify($value);

        return [
            'raw' => $value,
            'normalized' => $normalized,
            'category' => $category,
            'label' => self::labelFor($category),
            'reason' => self::AMBIGUOUS[$normalized] ?? self::REASONS[$category],
        ];
    }

    /**
     * @param  iterable<int, object{value_key: string, aggregate: int|string}>  $rows  Non-blank grouped values.
     * @return array{
     *     recorded: int,
     *     not_recorded: int,
     *     unclassified: int,
     *     total: int,
     *     categories: list<array{key: string, label: string}>,
     *     values: list<array{key: string, label: string, count: int, percent: float}>,
     *     definition: string
     * }
     */
    public static function aggregateGrouped(iterable $rows, int $blank = 0): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $counts[self::NOT_RECORDED] = max(0, $blank);

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $category = self::classifyNormalized((string) $row->value_key);
            $counts[$category] += $count;
        }

        $total = array_sum($counts);
        $values = [];
        foreach (self::CATEGORIES as $category => $label) {
            $count = $counts[$category];
            if ($count <= 0) {
                continue;
            }

            $values[] = [
                'key' => $category,
                'label' => $label,
                'count' => $count,
                'percent' => self::percent($count, $total),
            ];
        }

        return [
            'recorded' => $total - $counts[self::NOT_RECORDED],
            'not_recorded' => $counts[self::NOT_RECORDED],
            'unclassified' => $counts[self::UNCLASSIFIED],
            'total' => $total,
            'categories' => self::categories(),
            'values' => $values,
            'definition' => 'Each member is in exactly one occupation category. Percentages use all members in the current dashboard scope, including Not Recorded. Other / Unclassified means an occupation was stored but cannot be classified. Not Recorded means no occupation was stored.',
        ];
    }

    /**
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     */
    public static function applyListFilter(EloquentBuilder $query, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $category = self::categoryForFilter((string) $value);
        if ($category === null) {
            FamilyQueryFilters::applyNormalizedText($query, 'family_members.occupation', $value);

            return;
        }

        if ($category === self::NOT_RECORDED) {
            FamilyQueryFilters::applyNormalizedText($query, 'family_members.occupation', 'not_specified');

            return;
        }

        $expression = FamilyQueryFilters::normalizeSql('family_members.occupation');
        $matching = [];
        foreach (self::distinctNormalizedOccupations($query) as $key) {
            if (self::classify($key) === $category) {
                $matching[] = $key;
            }
        }

        if ($matching === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($matching), '?'));
        $query->whereRaw("{$expression} IN ({$placeholders})", $matching);
    }

    public static function categoryForFilter(string $value): ?string
    {
        $normalized = FamilyQueryFilters::normalizeLabel($value);
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['not_specified', 'not_recorded', 'not recorded'], true)) {
            return self::NOT_RECORDED;
        }

        foreach (self::CATEGORIES as $category => $label) {
            if ($normalized === $category || $normalized === FamilyQueryFilters::normalizeLabel($label)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Distinct occupation keys already in scope. Classification stays in PHP so
     * drill-down cannot drift from the graph. Cardinality is distinct titles, not members.
     *
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     * @return list<string>
     */
    private static function distinctNormalizedOccupations(EloquentBuilder $query): array
    {
        $expression = FamilyQueryFilters::normalizeSql('family_members.occupation');
        $clone = (clone $query)->setEagerLoads([]);
        $base = $clone->getQuery();
        $base->columns = null;
        $base->orders = null;
        $base->groups = null;
        $base->limit = null;
        $base->offset = null;

        return $clone
            ->selectRaw("{$expression} as value_key")
            ->whereNotNull('family_members.occupation')
            ->whereRaw("TRIM(family_members.occupation) <> ''")
            ->groupByRaw($expression)
            ->pluck('value_key')
            ->map(static fn ($key): string => (string) $key)
            ->filter(static fn (string $key): bool => $key !== '')
            ->values()
            ->all();
    }

    private static function isStudent(string $value): bool
    {
        if (preg_match('/^students?$/u', $value) === 1) {
            return true;
        }
        if (preg_match('/^students?\s/u', $value) === 1) {
            return true;
        }

        return preg_match('/\sstudents?$/u', $value) === 1;
    }

    private static function isFormerOrRetired(string $value): bool
    {
        if (preg_match('/\b(?:retired|pensioners?|retd)\b/u', $value) === 1) {
            return true;
        }
        if (preg_match('/^former\b/u', $value) === 1) {
            return true;
        }

        return preg_match('/^ex[-\s]/u', $value) === 1;
    }

    private static function isGovernment(string $value): bool
    {
        if (preg_match('/\b(?:governments?|govt|psu)\b/u', $value) === 1) {
            return true;
        }
        if (preg_match('/\bcivil servants?\b/u', $value) === 1) {
            return true;
        }

        return preg_match('/public[\s-]sector/u', $value) === 1;
    }

    private static function isBusiness(string $value): bool
    {
        foreach ([
            'shop owner',
            'shopkeeper',
            'business owner',
            'small business owner',
            'restaurant owner',
            'business operator',
        ] as $phrase) {
            if (str_contains($value, $phrase)) {
                return true;
            }
        }

        return preg_match('/\b(?:self[-\s]?employed|entrepreneurs?|proprietors?|retailers?|wholesalers?|traders?|contractors?)\b/u', $value) === 1;
    }

    private static function isProfessional(string $value): bool
    {
        if (str_contains($value, 'chartered accountant')) {
            return true;
        }
        if (preg_match('/^consultants?$/u', $value) === 1 || str_contains($value, 'professional consultant')) {
            return true;
        }

        return preg_match('/\b(?:engineers?|developers?|programmers?|doctors?|dentists?|lawyers?|advocates?|architects?|pharmacists?|nurses?|physicians?)\b/u', $value) === 1;
    }

    private static function isLabour(string $value): bool
    {
        foreach ([
            'construction worker',
            'factory worker',
            'machine operator',
            'agricultural labourer',
            'agricultural laborer',
            'daily wage',
        ] as $phrase) {
            if (str_contains($value, $phrase)) {
                return true;
            }
        }

        return preg_match('/\b(?:electricians?|plumbers?|carpenters?|masons?|painters?|welders?|mechanics?|fitters?|drivers?|technicians?|fisherman|fishermen|tailors?)\b/u', $value) === 1;
    }

    private static function isPrivateJob(string $value): bool
    {
        if (preg_match('/\b(?:private|pvt)\b/u', $value) === 1) {
            return true;
        }

        foreach ([
            'office employee',
            'office assistant',
            'office staff',
            'sales executive',
            'hr executive',
            'it employee',
            'administrative staff',
            'administrative assistant',
            'corporate employee',
            'company employee',
        ] as $phrase) {
            if (str_contains($value, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function isHomemaker(string $value): bool
    {
        if (preg_match('/\b(?:homemakers?|housewives|housewife|homemaking)\b/u', $value) === 1) {
            return true;
        }

        foreach (['home maker', 'house wife', 'home duties', 'household work'] as $phrase) {
            if (str_contains($value, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function percent(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }
}
