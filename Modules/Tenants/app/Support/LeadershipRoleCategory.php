<?php

namespace Modules\Tenants\Support;

use Illuminate\Database\Eloquent\Builder;

final class LeadershipRoleCategory
{
    public const CANONICAL_DIOCESAN = 'CANONICAL_DIOCESAN';

    public const PARISH_CLERGY = 'PARISH_CLERGY';

    public const PARISH_COUNCIL = 'PARISH_COUNCIL';

    public const MINISTRY_PIOUS = 'MINISTRY_PIOUS';

    public const OTHER = 'OTHER';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CANONICAL_DIOCESAN,
            self::PARISH_CLERGY,
            self::PARISH_COUNCIL,
            self::MINISTRY_PIOUS,
            self::OTHER,
        ];
    }

    public static function label(string $category): string
    {
        return match ($category) {
            self::CANONICAL_DIOCESAN => 'Diocesan / Canonical',
            self::PARISH_CLERGY => 'Parish Clergy',
            self::PARISH_COUNCIL => 'Parish Councils',
            self::MINISTRY_PIOUS => 'Ministries',
            default => 'Other',
        };
    }

    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    public static function hierarchicalLevel(string $category): int
    {
        return match ($category) {
            self::CANONICAL_DIOCESAN => 1,
            self::PARISH_CLERGY => 2,
            self::PARISH_COUNCIL => 3,
            default => 4,
        };
    }

    public static function guessFromTitle(string $title): string
    {
        $lower = strtolower(trim($title));

        if ($lower === '') {
            return self::OTHER;
        }

        if (
            str_contains($lower, 'pastor')
            || str_contains($lower, 'priest')
            || str_contains($lower, 'deacon')
            || str_contains($lower, 'vicar')
        ) {
            return self::PARISH_CLERGY;
        }

        if (str_contains($lower, 'council') || str_contains($lower, 'chair')) {
            return self::PARISH_COUNCIL;
        }

        if (str_contains($lower, 'bishop') || str_contains($lower, 'archbishop')) {
            return self::CANONICAL_DIOCESAN;
        }

        if (str_contains($lower, 'choir') || str_contains($lower, 'youth') || str_contains($lower, 'ministry')) {
            return self::MINISTRY_PIOUS;
        }

        return self::OTHER;
    }

    /**
     * Match canonical parish clergy roles and legacy custom titles miscategorized as OTHER.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyParishClergyRoleFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $categoryQuery): void {
            $categoryQuery->where('category', self::PARISH_CLERGY)
                ->orWhere(function (Builder $legacyQuery): void {
                    $legacyQuery->where('category', self::OTHER)
                        ->where(function (Builder $titleQuery): void {
                            $keywords = ['pastor', 'priest', 'deacon', 'vicar'];
                            $driver = $titleQuery->getConnection()->getDriverName();

                            foreach ($keywords as $keyword) {
                                $pattern = '%'.$keyword.'%';

                                if ($driver === 'pgsql') {
                                    $titleQuery->orWhere('title', 'ILIKE', $pattern);
                                } else {
                                    $titleQuery->orWhereRaw('LOWER(title) LIKE LOWER(?)', [$pattern]);
                                }
                            }
                        });
                });
        });
    }
}
