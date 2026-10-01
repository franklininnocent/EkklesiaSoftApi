<?php

namespace Modules\Family\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Consolidated education levels for family_members.education.
 *
 * The column stays free text and is never rewritten. Analytics assign each member
 * to exactly one category. Institution names, streams, specializations, and trades
 * are not categories. Explicit qualification tokens win over generic wording.
 *
 * A token matches the whole value, or the token followed by a separator
 * (space, comma, slash, or dash) and further detail. "B.Ed" is tested before "B.E."
 * so it cannot fall through to Professional Education.
 */
final class MemberEducationQualification
{
    public const NOT_RECORDED = 'not_recorded';

    public const UNCLASSIFIED = 'unclassified';

    /**
     * Display order. Zero-count categories are omitted from the graph.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'school' => 'School Education (Classes 1–12)',
        'iti' => 'ITI / Vocational',
        'diploma' => 'Diploma',
        'ug' => 'Undergraduate (UG)',
        'pg' => 'Postgraduate (PG)',
        'professional' => 'Professional Education',
        'certification' => 'Other Certifications',
        self::UNCLASSIFIED => 'Other / Unclassified',
        self::NOT_RECORDED => 'Not Recorded',
    ];

    /**
     * Most specific token first. Aliases cover dots and spacing only.
     *
     * @var array<string, string>
     */
    private const TOKENS = [
        'b.ed' => 'pg',
        'bed' => 'pg',
        'm.tech' => 'professional',
        'mtech' => 'professional',
        'm.sc' => 'pg',
        'msc' => 'pg',
        'm.com' => 'pg',
        'mcom' => 'pg',
        'mba' => 'pg',
        'mca' => 'pg',
        'ma' => 'pg',
        'b.tech' => 'professional',
        'btech' => 'professional',
        'b.e.' => 'professional',
        'b.e' => 'professional',
        'be' => 'professional',
        'b.sc' => 'ug',
        'bsc' => 'ug',
        'b.com' => 'ug',
        'bcom' => 'ug',
        'bba' => 'ug',
        'bca' => 'ug',
        'ba' => 'ug',
        'plus two' => 'school',
        'iti' => 'iti',
        'diploma' => 'diploma',
    ];

    /** @var list<string> */
    private const SEPARATORS = [' ', ',', '-', '—', '–', '/'];

    public static function classify(?string $value): ?string
    {
        $normalized = FamilyQueryFilters::normalizeLabel((string) $value);
        if ($normalized === '') {
            return self::NOT_RECORDED;
        }

        return self::classifyNormalized($normalized) ?? self::UNCLASSIFIED;
    }

    public static function classifyNormalized(string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        foreach (self::TOKENS as $token => $category) {
            if (self::tokenMatches($normalized, $token)) {
                return $category;
            }
        }

        return null;
    }

    public static function labelFor(string $category): string
    {
        return self::CATEGORIES[$category] ?? $category;
    }

    /**
     * @param  iterable<int, object{value_key: string, aggregate: int|string}>  $rows  Non-blank grouped values.
     * @return array{recorded: int, excluded: int, not_recorded: int, total: int, values: list<array{key: string, label: string, count: int, percent: float}>}
     */
    public static function aggregateGrouped(iterable $rows, int $blank = 0): array
    {
        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $counts[self::NOT_RECORDED] = max(0, $blank);

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $normalized = FamilyQueryFilters::normalizeLabel((string) $row->value_key);
            if ($normalized === '') {
                $counts[self::NOT_RECORDED] += $count;

                continue;
            }

            $category = self::classifyNormalized($normalized) ?? self::UNCLASSIFIED;
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
                'key' => $label,
                'label' => $label,
                'count' => $count,
                'percent' => self::percent($count, $total),
            ];
        }

        return [
            'recorded' => $total - $counts[self::NOT_RECORDED] - $counts[self::UNCLASSIFIED],
            'excluded' => $counts[self::UNCLASSIFIED],
            'not_recorded' => $counts[self::NOT_RECORDED],
            'total' => $total,
            'values' => $values,
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

        $normalized = FamilyQueryFilters::normalizeLabel((string) $value);
        if ($normalized === 'not_specified' || $normalized === self::NOT_RECORDED || $normalized === FamilyQueryFilters::normalizeLabel(self::labelFor(self::NOT_RECORDED))) {
            FamilyQueryFilters::applyNormalizedText($query, 'family_members.education', 'not_specified');

            return;
        }

        $category = self::categoryForFilter($normalized);
        if ($category === self::UNCLASSIFIED) {
            self::applyUnclassified($query);

            return;
        }

        if ($category !== null) {
            self::whereCategory($query, $category);

            return;
        }

        FamilyQueryFilters::applyNormalizedText($query, 'family_members.education', $value);
    }

    /**
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     */
    private static function applyUnclassified(EloquentBuilder $query): void
    {
        $norm = self::normalizedColumn();
        $query->whereNotNull('family_members.education')
            ->whereRaw("TRIM(family_members.education) <> ''");

        foreach (array_keys(self::TOKENS) as $token) {
            self::whereTokenDoesNotMatch($query, $norm, $token);
        }
    }

    /**
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     */
    private static function whereCategory(EloquentBuilder $query, string $category): void
    {
        $tokens = [];
        foreach (self::TOKENS as $token => $tokenCategory) {
            if ($tokenCategory === $category) {
                $tokens[] = $token;
            }
        }

        if ($tokens === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($outer) use ($tokens): void {
            foreach ($tokens as $index => $token) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $outer->{$method}(function ($inner) use ($token): void {
                    self::whereTokenMatches($inner, self::normalizedColumn(), $token);
                });
            }
        });
    }

    /**
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     */
    private static function whereTokenMatches(EloquentBuilder $query, string $norm, string $token): void
    {
        $query->where(function ($inner) use ($norm, $token): void {
            $inner->whereRaw("{$norm} = ?", [$token]);
            foreach (self::SEPARATORS as $separator) {
                $inner->orWhereRaw("{$norm} LIKE ?", [$token.$separator.'%']);
            }
        });
    }

    /**
     * @param  EloquentBuilder<\Modules\Family\Models\FamilyMember>  $query
     */
    private static function whereTokenDoesNotMatch(EloquentBuilder $query, string $norm, string $token): void
    {
        $query->whereRaw("COALESCE({$norm}, '') <> ?", [$token]);
        foreach (self::SEPARATORS as $separator) {
            $query->whereRaw("COALESCE({$norm}, '') NOT LIKE ?", [$token.$separator.'%']);
        }
    }

    private static function categoryForFilter(string $normalized): ?string
    {
        foreach (self::CATEGORIES as $category => $label) {
            if ($normalized === $category || $normalized === FamilyQueryFilters::normalizeLabel($label)) {
                return $category;
            }
        }

        return null;
    }

    private static function tokenMatches(string $value, string $token): bool
    {
        if ($value === $token) {
            return true;
        }

        foreach (self::SEPARATORS as $separator) {
            if (str_starts_with($value, $token.$separator)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizedColumn(): string
    {
        return FamilyQueryFilters::normalizeSql('family_members.education');
    }

    private static function percent(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }
}
