<?php

namespace Modules\Family\Support;

use Illuminate\Support\Collection;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

/** Person name of the family head — never the household `family_name` label. */
final class FamilyHeadDisplayName
{
    public static function resolve(?Family $family): ?string
    {
        if (! $family) {
            return null;
        }

        if ($family->relationLoaded('members')) {
            $fromMembers = self::fromMembers($family->members);
            if ($fromMembers !== null && $fromMembers !== '') {
                return $fromMembers;
            }
        }

        $head = trim((string) ($family->head_of_family ?? ''));

        return $head !== '' ? $head : null;
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     */
    private static function fromMembers(Collection $members): ?string
    {
        $candidates = $members->filter(
            fn (FamilyMember $member) => in_array($member->relationship_to_head, ['self', 'head'], true)
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        $head = $candidates->first(fn (FamilyMember $member) => $member->status === 'active')
            ?? $candidates->first();

        if (! $head) {
            return null;
        }

        return trim(implode(' ', array_filter([
            $head->first_name,
            $head->middle_name,
            $head->last_name,
        ])));
    }
}
