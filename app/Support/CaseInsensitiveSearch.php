<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Database-agnostic case-insensitive LIKE helpers.
 *
 * PostgreSQL uses native ILIKE; SQLite/MySQL use LOWER(column) LIKE LOWER(?).
 * Column identifiers must be trusted literals from application code — never user input.
 * Leading-wildcard patterns cannot use B-tree indexes on any driver.
 */
final class CaseInsensitiveSearch
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyColumnLike(
        Builder $query,
        string $column,
        string $pattern,
        string $boolean = 'and'
    ): Builder {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            return $boolean === 'or'
                ? $query->orWhere($column, 'ILIKE', $pattern)
                : $query->where($column, 'ILIKE', $pattern);
        }

        $expression = "LOWER({$column}) LIKE LOWER(?)";

        return $boolean === 'or'
            ? $query->orWhereRaw($expression, [$pattern])
            : $query->whereRaw($expression, [$pattern]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyMemberFullNameLike(
        Builder $query,
        string $pattern,
        string $boolean = 'and'
    ): Builder {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $sql = "CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) ILIKE ?";
        } elseif ($driver === 'sqlite') {
            $sql = "(first_name || ' ' || COALESCE(middle_name, '') || ' ' || last_name) LIKE ? COLLATE NOCASE";
        } else {
            $sql = "LOWER(CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)) LIKE LOWER(?)";
        }

        return $boolean === 'or'
            ? $query->orWhereRaw($sql, [$pattern])
            : $query->whereRaw($sql, [$pattern]);
    }
}
