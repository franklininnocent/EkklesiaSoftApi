<?php

namespace Modules\Family\Support;

use App\Support\CaseInsensitiveSearch;
use Illuminate\Database\Eloquent\Builder;
use Modules\Family\Models\FamilyMember;

/**
 * Parish member directory text search: member name and father name only (list UI columns).
 */
final class MemberDirectorySearch
{
    /**
     * @param  Builder<FamilyMember>  $query
     */
    public static function apply(Builder $query, string $pattern, int|string $tenantId): void
    {
        $table = $query->getModel()->getTable();

        $query->where(function (Builder $outer) use ($pattern, $tenantId, $table): void {
            CaseInsensitiveSearch::applyMemberFullNameLikeOnTable($outer, $table, $pattern);
            CaseInsensitiveSearch::applyColumnLike($outer, "{$table}.first_name", $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($outer, "{$table}.middle_name", $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($outer, "{$table}.last_name", $pattern, 'or');

            CaseInsensitiveSearch::applyColumnLike($outer, "{$table}.marriage_bride_father_name", $pattern, 'or');
            CaseInsensitiveSearch::applyColumnLike($outer, "{$table}.marriage_groom_father_name", $pattern, 'or');

            $outer->orWhereHas('person', function (Builder $personQuery) use ($pattern): void {
                CaseInsensitiveSearch::applyColumnLike($personQuery, 'persons.father_name', $pattern);
                $personQuery->orWhereHas('father', function (Builder $fatherQuery) use ($pattern): void {
                    CaseInsensitiveSearch::applyMemberFullNameLike($fatherQuery, $pattern);
                });
            });

            $outer->orWhereExists(function ($sub) use ($pattern, $tenantId): void {
                $sub->selectRaw('1')
                    ->from('family_members as household_match')
                    ->whereColumn('household_match.family_id', 'family_members.family_id')
                    ->where('household_match.tenant_id', $tenantId)
                    ->whereNull('household_match.deleted_at')
                    ->whereColumn('household_match.id', '!=', 'family_members.id')
                    ->where(function ($relationshipQuery): void {
                        $relationshipQuery
                            ->where('household_match.relationship_to_head', 'father')
                            ->orWhere(function ($headQuery): void {
                                $headQuery
                                    ->whereIn('household_match.relationship_to_head', ['self', 'head'])
                                    ->where('household_match.gender', 'male');
                            });
                    })
                    ->where(function ($nameQuery) use ($pattern): void {
                        CaseInsensitiveSearch::applyMemberFullNameLikeOnTable(
                            $nameQuery,
                            'household_match',
                            $pattern
                        );
                        CaseInsensitiveSearch::applyColumnLike($nameQuery, 'household_match.first_name', $pattern, 'or');
                        CaseInsensitiveSearch::applyColumnLike($nameQuery, 'household_match.last_name', $pattern, 'or');
                    });
            });
        });
    }
}
