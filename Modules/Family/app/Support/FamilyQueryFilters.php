<?php

namespace Modules\Family\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Support\BccAgeBands;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

final class FamilyQueryFilters
{
    public const UNASSIGNED_BCC = 'unassigned';

    public const SIZE_BANDS = ['1', '2', '3_4', '5_6', '7_plus'];

    public const HOUSEHOLDS = ['under_18', 'seniors', 'multiple_adults'];

    public const FAMILY_MISSING = ['head', 'contact', 'address', 'members'];

    public const MEMBER_MISSING = ['dob', 'gender', 'relationship'];

    public static function applyBcc(EloquentBuilder|QueryBuilder $query, mixed $bccId, string $column = 'bcc_id'): void
    {
        if ($bccId === null || $bccId === '') {
            return;
        }

        if ((string) $bccId === self::UNASSIGNED_BCC) {
            $query->whereNull($column);

            return;
        }

        $query->where($column, (string) $bccId);
    }

    public static function applyFamilyStatus(EloquentBuilder|QueryBuilder $query, mixed $status, string $column = 'status'): void
    {
        if ($status === null || $status === '') {
            return;
        }

        $normalized = strtolower(trim((string) $status));
        if (in_array($normalized, ['active', 'inactive', 'migrated'], true)) {
            $query->where($column, $normalized);
        }
    }

    /**
     * @param  EloquentBuilder<Family>  $query
     */
    public static function applyFamilyMissing(EloquentBuilder $query, string $tenantId, mixed $missing): void
    {
        $key = strtolower(trim((string) $missing));
        if (! in_array($key, self::FAMILY_MISSING, true)) {
            return;
        }

        match ($key) {
            'head' => $query->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->where('family_members.status', 'active')
                    ->whereIn('family_members.relationship_to_head', ['self', 'head']);
            }),
            'contact' => $query->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', $tenantId)
                    ->whereNull('family_members.deleted_at')
                    ->whereNotNull('family_members.phone')
                    ->whereRaw("TRIM(family_members.phone) <> ''");
            }),
            'address' => $query->where(function ($inner): void {
                $inner->whereNull('families.address_line_1')
                    ->orWhereRaw("TRIM(families.address_line_1) = ''");
            }),
            'members' => $query->whereNotExists(function ($sub) use ($tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members')
                    ->whereColumn('family_members.family_id', 'families.id')
                    ->where('family_members.tenant_id', $tenantId)
                    ->whereNull('family_members.deleted_at');
            }),
            default => null,
        };
    }

    /**
     * @param  EloquentBuilder<Family>  $query
     */
    public static function applySizeBand(EloquentBuilder $query, string $tenantId, mixed $band): void
    {
        $key = (string) $band;
        if (! in_array($key, self::SIZE_BANDS, true)) {
            return;
        }

        $having = match ($key) {
            '1' => 'COUNT(*) = 1',
            '2' => 'COUNT(*) = 2',
            '3_4' => 'COUNT(*) BETWEEN 3 AND 4',
            '5_6' => 'COUNT(*) BETWEEN 5 AND 6',
            default => 'COUNT(*) >= 7',
        };

        $query->whereIn('families.id', function ($sub) use ($tenantId, $having): void {
            $sub->select('family_id')
                ->from('family_members')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->groupBy('family_id')
                ->havingRaw($having);
        });
    }

    /**
     * @param  EloquentBuilder<Family>  $query
     */
    public static function applyHousehold(EloquentBuilder $query, string $tenantId, mixed $household): void
    {
        $key = (string) $household;
        if (! in_array($key, self::HOUSEHOLDS, true)) {
            return;
        }

        $ageYears = BccAgeBands::ageYearsSql();

        if ($key === 'multiple_adults') {
            $query->whereIn('families.id', function ($sub) use ($tenantId, $ageYears): void {
                $sub->select('family_id')
                    ->from('family_members')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->whereNotNull('date_of_birth')
                    ->whereRaw("{$ageYears} >= 18")
                    ->groupBy('family_id')
                    ->havingRaw('COUNT(*) >= 2');
            });

            return;
        }

        $ageClause = $key === 'under_18'
            ? "{$ageYears} < 18"
            : "{$ageYears} >= 60";

        $query->whereExists(function ($sub) use ($tenantId, $ageClause): void {
            $sub->selectRaw('1')
                ->from('family_members')
                ->whereColumn('family_members.family_id', 'families.id')
                ->where('family_members.tenant_id', $tenantId)
                ->whereNull('family_members.deleted_at')
                ->whereNotNull('family_members.date_of_birth')
                ->whereRaw($ageClause);
        });
    }

    /**
     * @param  EloquentBuilder<FamilyMember>  $query
     */
    public static function applyMemberMissing(EloquentBuilder $query, mixed $missing): void
    {
        $key = strtolower(trim((string) $missing));
        if (! in_array($key, self::MEMBER_MISSING, true)) {
            return;
        }

        match ($key) {
            'dob' => $query->whereNull('family_members.date_of_birth'),
            'gender' => $query->where(function ($inner): void {
                $inner->whereNull('family_members.gender')
                    ->orWhereRaw("TRIM(family_members.gender) = ''");
            }),
            'relationship' => $query->whereNull('family_members.relationship_to_head'),
            default => null,
        };
    }

    /**
     * @param  EloquentBuilder<FamilyMember>  $query
     */
    public static function applyGender(EloquentBuilder $query, mixed $gender): void
    {
        $key = strtolower(trim((string) $gender));
        if ($key === '') {
            return;
        }

        if ($key === 'unknown') {
            $query->where(function ($inner): void {
                $inner->whereNull('family_members.gender')
                    ->orWhereRaw("TRIM(family_members.gender) = ''");
            });

            return;
        }

        if (in_array($key, ['male', 'female', 'other'], true)) {
            $query->whereRaw('LOWER(TRIM(family_members.gender)) = ?', [$key]);
        }
    }

    /**
     * @param  EloquentBuilder<FamilyMember>  $query
     */
    public static function applyNormalizedText(EloquentBuilder $query, string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = self::normalizeLabel((string) $value);
        if ($normalized === 'not_specified') {
            $query->where(function ($inner) use ($column): void {
                $inner->whereNull($column)
                    ->orWhereRaw("TRIM({$column}) = ''");
            });

            return;
        }

        $query->whereRaw(self::normalizeSql($column).' = ?', [$normalized]);
    }

    public static function normalizeSql(string $column): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            return "LOWER(REGEXP_REPLACE(TRIM({$column}), '\\s+', ' ', 'g'))";
        }

        return "LOWER(TRIM({$column}))";
    }

    public static function normalizeLabel(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return strtolower($collapsed);
    }

    /**
     * @param  EloquentBuilder<Family>  $query
     */
    public static function applyCityExact(EloquentBuilder $query, mixed $city): void
    {
        if ($city === null || $city === '') {
            return;
        }

        $normalized = self::normalizeLabel((string) $city);
        if ($normalized === 'not_recorded') {
            $query->where(function ($inner): void {
                $inner->whereNull('families.city')
                    ->orWhereRaw("TRIM(families.city) = ''");
            });

            return;
        }

        $query->whereRaw(self::normalizeSql('families.city').' = ?', [$normalized]);
    }
}
