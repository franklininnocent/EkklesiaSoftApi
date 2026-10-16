<?php

namespace Modules\Tenants\Support;

use Illuminate\Http\Request;

/**
 * Platform-wide pagination guardrails for list endpoints.
 */
class ApiPagination
{
    public static function maxPerPage(): int
    {
        return max(1, (int) config('tenants.api.pagination.max_per_page', 100));
    }

    public static function defaultPerPage(): int
    {
        $default = (int) config('tenants.api.pagination.default_per_page', 20);

        return max(1, min(self::maxPerPage(), $default));
    }

    public static function clamp(?int $perPage, ?int $default = null): int
    {
        $default ??= self::defaultPerPage();
        $value = $perPage ?? $default;

        return max(1, min(self::maxPerPage(), $value));
    }

    public static function clampFromRequest(Request $request, ?int $default = null): int
    {
        if (! $request->has('per_page')) {
            return $default ?? self::defaultPerPage();
        }

        $raw = $request->input('per_page');
        if ($raw === 'all' || $raw === null || $raw === '') {
            return $default ?? self::defaultPerPage();
        }

        return self::clamp((int) $raw, $default);
    }
}
