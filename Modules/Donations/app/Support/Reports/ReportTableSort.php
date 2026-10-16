<?php

namespace Modules\Donations\Support\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReportTableSort
{
    public static function direction(ReportFilter $filter): string
    {
        return strtolower((string) $filter->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function column(ReportFilter $filter, array $allowed): ?string
    {
        $sort = $filter->get('sort');
        if (! is_string($sort) || $sort === '') {
            return null;
        }

        return in_array($sort, $allowed, true) ? $sort : null;
    }

    /**
     * @param  array<string, string>  $columnToSql
     * @param  callable(Builder): void|null  $default
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyToQuery(Builder $query, ReportFilter $filter, array $columnToSql, ?callable $default = null): Builder
    {
        $col = self::column($filter, array_keys($columnToSql));
        $query->reorder();
        if ($col !== null) {
            $query->orderBy($columnToSql[$col], self::direction($filter));

            return $query;
        }
        if ($default !== null) {
            $default($query);
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $allowed
     * @return list<array<string, mixed>>
     */
    public static function sortArrayRows(array $rows, ReportFilter $filter, array $allowed): array
    {
        $col = self::column($filter, $allowed);
        if ($col === null) {
            return $rows;
        }
        $dir = self::direction($filter);
        usort($rows, static function (array $a, array $b) use ($col, $dir): int {
            $cmp = self::compareValues($a[$col] ?? null, $b[$col] ?? null);

            return $dir === 'desc' ? -$cmp : $cmp;
        });

        return $rows;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $allowed
     * @return Collection<int, array<string, mixed>>
     */
    public static function sortCollection(Collection $rows, ReportFilter $filter, array $allowed): Collection
    {
        return collect(self::sortArrayRows($rows->all(), $filter, $allowed));
    }

    private static function compareValues(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return strnatcasecmp((string) $a, (string) $b);
    }
}
