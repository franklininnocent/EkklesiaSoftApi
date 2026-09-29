<?php

namespace Modules\Family\app\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Modules\Family\app\Support\ParishCalendar;
use Modules\Family\Models\FamilyMember;

class MemberCelebrationsService
{
    /**
     * @return array<string, mixed>
     */
    public function weekCelebrations(int $tenantId): array
    {
        $bounds = ParishCalendar::currentWeekBounds($tenantId);
        $weekStart = $bounds['start'];
        $weekEnd = $bounds['end'];
        $weekDays = ParishCalendar::weekDayOccurrences($weekStart, $weekEnd);

        $birthdayMembers = $this->membersMatchingMonthDays($tenantId, 'date_of_birth', $weekDays, $weekEnd);
        $anniversaryMembers = $this->membersMatchingMonthDays($tenantId, 'marriage_date', $weekDays, $weekEnd);

        $birthdays = $this->mapBirthdays($birthdayMembers, $weekDays);
        $anniversaries = $this->mapAnniversaries($anniversaryMembers, $weekDays);

        return [
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'label' => ParishCalendar::weekRangeLabel($weekStart, $weekEnd),
                'timezone' => $bounds['timezone'],
            ],
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
        ];
    }

    /**
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     * @return \Illuminate\Support\Collection<int, FamilyMember>
     */
    private function membersMatchingMonthDays(
        int $tenantId,
        string $dateColumn,
        array $weekDays,
        Carbon $weekEnd
    ) {
        return FamilyMember::query()
            ->forTenant($tenantId)
            ->active()
            ->whereNotNull($dateColumn)
            ->whereHas('family', function (Builder $query) use ($tenantId): void {
                $query->where('tenant_id', $tenantId)->where('status', 'active');
            })
            ->where(function (Builder $query) use ($dateColumn, $weekDays, $weekEnd): void {
                foreach ($weekDays as $day) {
                    $query->orWhere(function (Builder $inner) use ($dateColumn, $day): void {
                        $inner->whereMonth($dateColumn, $day['month'])
                            ->whereDay($dateColumn, $day['day']);
                    });
                }

                if (! $weekEnd->isLeapYear()) {
                    foreach ($weekDays as $day) {
                        if ($day['month'] === 2 && $day['day'] === 28) {
                            $query->orWhere(function (Builder $inner) use ($dateColumn): void {
                                $inner->whereMonth($dateColumn, 2)->whereDay($dateColumn, 29);
                            });
                            break;
                        }
                    }
                }
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'family_id', 'first_name', 'middle_name', 'last_name', 'date_of_birth', 'marriage_date', 'marriage_spouse_name', 'relationship_to_head', 'status']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FamilyMember>  $members
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     * @return list<array<string, mixed>>
     */
    private function mapBirthdays($members, array $weekDays): array
    {
        $items = [];

        foreach ($members as $member) {
            if (! $member->date_of_birth) {
                continue;
            }

            $eventDate = $this->resolveEventDate($member->date_of_birth, $weekDays);
            if ($eventDate === null) {
                continue;
            }

            $turningAge = $this->upcomingAge($member->date_of_birth, $eventDate);

            $items[] = [
                'id' => $member->id,
                'family_id' => $member->family_id,
                'name' => $member->full_name_display,
                'day_label' => $eventDate->format('D'),
                'date_label' => $eventDate->format('M j'),
                'detail' => $turningAge !== null ? "Turning {$turningAge}" : 'Birthday',
                'event_date' => $eventDate->toDateString(),
            ];
        }

        return $this->sortCelebrations($items);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FamilyMember>  $members
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     * @return list<array<string, mixed>>
     */
    private function mapAnniversaries($members, array $weekDays): array
    {
        $grouped = [];

        foreach ($members as $member) {
            if (! $member->marriage_date) {
                continue;
            }

            $eventDate = $this->resolveEventDate($member->marriage_date, $weekDays);
            if ($eventDate === null) {
                continue;
            }

            $key = $member->family_id.'|'.$member->marriage_date->toDateString();
            $existing = $grouped[$key] ?? null;

            if ($existing === null || $this->isPreferredAnniversaryMember($member, $existing)) {
                $grouped[$key] = $member;
            }
        }

        $items = [];
        foreach ($grouped as $member) {
            $eventDate = $this->resolveEventDate($member->marriage_date, $weekDays);
            if ($eventDate === null) {
                continue;
            }

            $years = max(0, $eventDate->year - $member->marriage_date->year);
            $spouse = trim((string) $member->marriage_spouse_name);
            $name = $spouse !== ''
                ? "{$member->full_name_display} & {$spouse}"
                : $member->full_name_display;

            $items[] = [
                'id' => "{$member->id}-anniversary",
                'family_id' => $member->family_id,
                'name' => $name,
                'day_label' => $eventDate->format('D'),
                'date_label' => $eventDate->format('M j'),
                'detail' => $years > 0 ? "{$years} years together" : 'Wedding anniversary',
                'event_date' => $eventDate->toDateString(),
            ];
        }

        return $this->sortCelebrations($items);
    }

    /**
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     */
    private function resolveEventDate(Carbon $sourceDate, array $weekDays): ?Carbon
    {
        $birthMonth = (int) $sourceDate->month;
        $birthDay = (int) $sourceDate->day;

        foreach ($weekDays as $day) {
            if ($day['month'] === $birthMonth && $day['day'] === $birthDay) {
                return $day['date']->copy();
            }

            if ($birthMonth === 2 && $birthDay === 29 && $day['month'] === 2 && $day['day'] === 28 && ! $day['date']->isLeapYear()) {
                return $day['date']->copy();
            }
        }

        return null;
    }

    private function upcomingAge(Carbon $dateOfBirth, Carbon $eventDate): ?int
    {
        $age = $dateOfBirth->diffInYears($eventDate);
        if ($eventDate->lt($dateOfBirth)) {
            return null;
        }

        return (int) $age + 1;
    }

    private function isPreferredAnniversaryMember(FamilyMember $candidate, FamilyMember $current): bool
    {
        $candidateRank = $this->anniversaryMemberRank($candidate);
        $currentRank = $this->anniversaryMemberRank($current);

        if ($candidateRank !== $currentRank) {
            return $candidateRank < $currentRank;
        }

        return strcmp((string) $candidate->id, (string) $current->id) < 0;
    }

    private function anniversaryMemberRank(FamilyMember $member): int
    {
        $relationship = strtolower((string) $member->relationship_to_head);

        return in_array($relationship, ['self', 'head'], true) ? 0 : 1;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortCelebrations(array $items): array
    {
        $weekdayOrder = ['Mon' => 0, 'Tue' => 1, 'Wed' => 2, 'Thu' => 3, 'Fri' => 4, 'Sat' => 5, 'Sun' => 6];

        usort($items, function (array $left, array $right) use ($weekdayOrder): int {
            $leftDay = $weekdayOrder[$left['day_label']] ?? 99;
            $rightDay = $weekdayOrder[$right['day_label']] ?? 99;

            if ($leftDay !== $rightDay) {
                return $leftDay <=> $rightDay;
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        });

        return $items;
    }
}
