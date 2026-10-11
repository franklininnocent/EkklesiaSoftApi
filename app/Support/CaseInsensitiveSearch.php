<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
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
    public static function applyColumnLike(
        QueryBuilderContract $query,
        string $column,
        string $pattern,
        string $boolean = 'and'
    ): QueryBuilderContract {
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
        return self::applyMemberFullNameLikeOnTable(
            $query,
            $query->getModel()->getTable(),
            $pattern,
            $boolean
        );
    }

    public static function applyMemberFullNameLikeOnTable(
        QueryBuilderContract $query,
        string $table,
        string $pattern,
        string $boolean = 'and'
    ): QueryBuilderContract {
        $driver = $query->getConnection()->getDriverName();
        $firstName = "{$table}.first_name";
        $middleName = "{$table}.middle_name";
        $lastName = "{$table}.last_name";

        if ($driver === 'pgsql') {
            $sql = "REGEXP_REPLACE(TRIM(CONCAT(COALESCE({$firstName}, ''), ' ', COALESCE({$middleName}, ''), ' ', COALESCE({$lastName}, ''))), '\\s+', ' ', 'g') ILIKE ?";
        } elseif ($driver === 'sqlite') {
            $sql = "TRIM(REPLACE(REPLACE(REPLACE({$firstName} || ' ' || COALESCE({$middleName}, '') || ' ' || {$lastName}, '  ', ' '), '  ', ' '), '  ', ' ')) LIKE ? COLLATE NOCASE";
        } else {
            $sql = "LOWER(REGEXP_REPLACE(TRIM(CONCAT(COALESCE({$firstName}, ''), ' ', COALESCE({$middleName}, ''), ' ', COALESCE({$lastName}, ''))), '\\\\s+', ' ')) LIKE LOWER(?)";
        }

        return $boolean === 'or'
            ? $query->orWhereRaw($sql, [$pattern])
            : $query->whereRaw($sql, [$pattern]);
    }
}
