<?php

namespace Modules\RolesAndPermissions\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Product capabilities that are not implemented must never appear in RBAC catalogs.
 */
final class RetiredProductPermissionCatalog
{
    /** @var list<string> */
    public const RETIRED_MODULES = [
        'Attendance',
    ];

    /** @var list<string> */
    public const RETIRED_NAME_PREFIXES = [
        'attendance.',
    ];

    public static function applyExclusion(Builder $query): Builder
    {
        if (self::RETIRED_MODULES !== []) {
            $query->whereNotIn('module', self::RETIRED_MODULES);
        }

        foreach (self::RETIRED_NAME_PREFIXES as $prefix) {
            $query->where('name', 'not like', $prefix.'%');
        }

        return $query;
    }

    public static function isRetired(object $permission): bool
    {
        $module = (string) ($permission->module ?? '');
        if (in_array($module, self::RETIRED_MODULES, true)) {
            return true;
        }

        $name = (string) ($permission->name ?? '');
        foreach (self::RETIRED_NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
