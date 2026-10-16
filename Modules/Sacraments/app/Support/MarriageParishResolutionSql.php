<?php

namespace Modules\Sacraments\Support;

/**
 * SQL fragments to resolve bride/groom parish names for matrimony register analytics.
 */
final class MarriageParishResolutionSql
{
    public static function resolvedParishExpression(string $role, string $fallbackColumn): string
    {
        return "COALESCE(
            (SELECT NULLIF(TRIM(sp.affiliation_parish_name), '')
             FROM sacrament_participants sp
             WHERE sp.sacrament_id = sacraments.id
               AND sp.role = '{$role}'
               AND sp.deleted_at IS NULL
             ORDER BY sp.id
             LIMIT 1),
            NULLIF(TRIM(sacraments.{$fallbackColumn}), '')
        )";
    }
}
