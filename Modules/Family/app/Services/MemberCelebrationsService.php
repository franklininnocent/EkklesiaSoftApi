<?php

namespace Modules\Family\app\Services;

use App\Support\UserFacingDate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Family\app\Support\ParishCalendar;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Support\FamilyQueryFilters;

class MemberCelebrationsService
{
    /**
     * @return array<string, mixed>
     */
    public function weekCelebrations(int $tenantId): array
    {
        $bounds = ParishCalendar::currentWeekBounds($tenantId);
        $windowDays = ParishCalendar::weekDayOccurrences($bounds['start'], $bounds['end']);

        $birthdayMembers = $this->membersMatchingMonthDays($tenantId, 'date_of_birth', $windowDays, $bounds['end']);
        $anniversaryMembers = $this->membersMatchingMonthDays($tenantId, 'marriage_date', $windowDays, $bounds['end']);

        return [
            'week' => [
                'start' => $bounds['start']->toDateString(),
                'end' => $bounds['end']->toDateString(),
                'label' => ParishCalendar::weekRangeLabel($bounds['start'], $bounds['end']),
                'timezone' => $bounds['timezone'],
            ],
            'birthdays' => $this->mapBirthdays($birthdayMembers, $windowDays),
            'anniversaries' => $this->mapAnniversaries($anniversaryMembers, $windowDays),
        ];
    }

    /**
     * Same week window and grouping rules as {@see weekCelebrations()}, without family/BCC payloads.
     *
     * @return array<string, mixed>
     */
    public function weekCelebrationCounts(int $tenantId): array
    {
        $bounds = ParishCalendar::currentWeekBounds($tenantId);
        $windowDays = ParishCalendar::weekDayOccurrences($bounds['start'], $bounds['end']);

        return [
            'week' => [
                'start' => $bounds['start']->toDateString(),
                'end' => $bounds['end']->toDateString(),
                'label' => ParishCalendar::weekRangeLabel($bounds['start'], $bounds['end']),
                'timezone' => $bounds['timezone'],
            ],
            'birthdays_count' => $this->countMatchingMonthDays($tenantId, 'date_of_birth', $windowDays, $bounds['end']),
            'anniversaries_count' => $this->countAnniversaryGroups($tenantId, $windowDays, $bounds['end']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginatedCelebrations(int $tenantId, string $type, array $filters, int $page, int $perPage): array
    {
        $from = isset($filters['from']) && is_string($filters['from']) ? $filters['from'] : null;
        $to = isset($filters['to']) && is_string($filters['to']) ? $filters['to'] : null;
        $bounds = ParishCalendar::resolveCelebrationWindowBounds($tenantId, $from, $to);
        $windowDays = ParishCalendar::weekDayOccurrences($bounds['start'], $bounds['end']);
        $bccId = isset($filters['bcc_id']) && $filters['bcc_id'] !== '' ? (string) $filters['bcc_id'] : null;

        $dateColumn = $type === 'anniversaries' ? 'marriage_date' : 'date_of_birth';
        $members = $this->membersMatchingMonthDays($tenantId, $dateColumn, $windowDays, $bounds['end'], $bccId);

        $items = $type === 'anniversaries'
            ? $this->mapAnniversaries($members, $windowDays)
            : $this->mapBirthdays($members, $windowDays);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => str_contains(mb_strtolower((string) $item['name']), $needle)
                    || str_contains(mb_strtolower((string) ($item['family_name'] ?? '')), $needle)
            ));
        }

        $eventDateFrom = $filters['event_date_from'] ?? null;
        $eventDateTo = $filters['event_date_to'] ?? null;
        $windowStart = $bounds['start']->toDateString();
        $windowEnd = $bounds['end']->toDateString();
        $eventRangeDiffersFromWindow = ! is_string($eventDateFrom) || $eventDateFrom === ''
            || ! is_string($eventDateTo) || $eventDateTo === ''
            || $eventDateFrom !== $windowStart
            || $eventDateTo !== $windowEnd;

        if ($eventRangeDiffersFromWindow && is_string($eventDateFrom) && $eventDateFrom !== '' && is_string($eventDateTo) && $eventDateTo !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => ($item['event_date'] ?? '') >= $eventDateFrom
                    && ($item['event_date'] ?? '') <= $eventDateTo
            ));
        } else {
            $eventDate = $filters['event_date'] ?? null;
            if (is_string($eventDate) && $eventDate !== '') {
                $items = array_values(array_filter(
                    $items,
                    fn (array $item): bool => ($item['event_date'] ?? '') === $eventDate
                ));
            }
        }

        if ($bccId !== null && $bccId !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => (string) ($item['bcc_id'] ?? '') === $bccId
            ));
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'event_date');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $items = $this->sortCelebrationItems($items, $sortBy, $sortOrder);

        $total = count($items);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);

        return [
            'window' => [
                'start' => $bounds['start']->toDateString(),
                'end' => $bounds['end']->toDateString(),
                'label' => ParishCalendar::weekRangeLabel($bounds['start'], $bounds['end']),
                'timezone' => $bounds['timezone'],
            ],
            'type' => $type,
            'data' => $slice,
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'from' => $total === 0 ? null : $offset + 1,
            'to' => $total === 0 ? null : min($offset + $perPage, $total),
        ];
    }

    /**
     * Active members in active families whose month/day falls in the celebration window.
     *
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     */
    private function celebrationMembersQuery(
        int $tenantId,
        string $dateColumn,
        array $weekDays,
        Carbon $weekEnd,
        ?string $bccId = null
    ): Builder {
        $query = FamilyMember::query()
            ->forTenant($tenantId)
            ->active()
            ->whereNotNull($dateColumn)
            ->whereIn('family_id', function ($query) use ($tenantId, $bccId): void {
                $query->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->where('status', 'active');

                if ($bccId !== null && $bccId !== '') {
                    FamilyQueryFilters::applyBcc($query, $bccId);
                }
            });

        $this->applyMonthDayWindow($query, $dateColumn, $weekDays, $weekEnd);

        return $query;
    }

    /**
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     */
    private function applyMonthDayWindow(Builder $query, string $dateColumn, array $weekDays, Carbon $weekEnd): void
    {
        $query->where(function (Builder $query) use ($dateColumn, $weekDays, $weekEnd): void {
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
        });
    }

    /**
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     */
    private function countMatchingMonthDays(int $tenantId, string $dateColumn, array $weekDays, Carbon $weekEnd): int
    {
        return (int) $this->celebrationMembersQuery($tenantId, $dateColumn, $weekDays, $weekEnd)->count();
    }

    /**
     * One anniversary per family and wedding date, matching {@see mapAnniversaries()} grouping.
     *
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     */
    private function countAnniversaryGroups(int $tenantId, array $weekDays, Carbon $weekEnd): int
    {
        $grouped = $this->celebrationMembersQuery($tenantId, 'marriage_date', $weekDays, $weekEnd)
            ->select('family_members.family_id', 'family_members.marriage_date')
            ->groupBy('family_members.family_id', 'family_members.marriage_date');

        return (int) DB::query()->fromSub($grouped->toBase(), 'anniversary_groups')->count();
    }

    private function membersMatchingMonthDays(
        int $tenantId,
        string $dateColumn,
        array $weekDays,
        Carbon $weekEnd,
        ?string $bccId = null,
        bool $withFamilyContext = true
    ) {
        $query = $this->celebrationMembersQuery($tenantId, $dateColumn, $weekDays, $weekEnd, $bccId)
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($withFamilyContext) {
            $query->with(['family:id,tenant_id,family_name,family_code,bcc_id,status', 'family.bcc:id,name']);
        }

        return $query->get([
            'id',
            'family_id',
            'first_name',
            'middle_name',
            'last_name',
            'gender',
            'date_of_birth',
            'marriage_date',
            'marriage_spouse_name',
            'relationship_to_head',
            'status',
        ]);
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
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

            $items[] = array_merge(
                $this->familyContext($member),
                [
                    'id' => $member->id,
                    'family_id' => $member->family_id,
                    'name' => $member->full_name_display,
                    'day_label' => $eventDate->format('D'),
                    'date_label' => UserFacingDate::formatDayMonth($eventDate),
                    'detail' => $turningAge !== null ? "Turning {$turningAge}" : 'Birthday',
                    'event_date' => $eventDate->toDateString(),
                ]
            );
        }

        return $this->sortCelebrations($items);
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @param  list<array{month: int, day: int, date: Carbon}>  $weekDays
     * @return list<array<string, mixed>>
     */
    private function mapAnniversaries($members, array $weekDays): array
    {
        $partnersByKey = [];
        foreach ($members as $member) {
            if (! $member->marriage_date) {
                continue;
            }
            $key = $member->family_id.'|'.$member->marriage_date->toDateString();
            $partnersByKey[$key] ??= [];
            $partnersByKey[$key][] = $member;
        }

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
            $key = $member->family_id.'|'.$member->marriage_date->toDateString();
            $coupleMembers = $partnersByKey[$key] ?? [$member];
            $name = $this->formatAnniversaryCoupleName($coupleMembers);

            $items[] = array_merge(
                $this->familyContext($member),
                [
                    'id' => "{$member->id}-anniversary",
                    'family_id' => $member->family_id,
                    'name' => $name,
                    'day_label' => $eventDate->format('D'),
                    'date_label' => UserFacingDate::formatDayMonth($eventDate),
                    'detail' => $years > 0 ? "{$years} years together" : 'Wedding anniversary',
                    'event_date' => $eventDate->toDateString(),
                ]
            );
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
     * @param  list<FamilyMember>  $members  Same family and marriage date (one or both spouses).
     */
    private function formatAnniversaryCoupleName(array $members): string
    {
        $unique = [];
        foreach ($members as $member) {
            $unique[(string) $member->id] = $member;
        }
        $members = array_values($unique);

        $maleName = null;
        $femaleName = null;
        foreach ($members as $member) {
            if ($member->gender === 'male' && $maleName === null) {
                $maleName = $member->full_name_display;
            } elseif ($member->gender === 'female' && $femaleName === null) {
                $femaleName = $member->full_name_display;
            }
        }

        if ($maleName !== null && $femaleName !== null) {
            return "{$maleName} & {$femaleName}";
        }

        $member = $members[0];
        $spouseName = trim((string) $member->marriage_spouse_name);
        if ($spouseName === '') {
            return $member->full_name_display;
        }

        if ($member->gender === 'female') {
            return "{$spouseName} & {$member->full_name_display}";
        }

        if ($member->gender === 'male') {
            return "{$member->full_name_display} & {$spouseName}";
        }

        return "{$member->full_name_display} & {$spouseName}";
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortCelebrations(array $items): array
    {
        return $this->sortCelebrationItems($items, 'event_date', 'asc');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortCelebrationItems(array $items, string $sortBy, string $sortOrder): array
    {
        $direction = $sortOrder === 'desc' ? -1 : 1;

        usort($items, function (array $left, array $right) use ($sortBy, $direction): int {
            $leftValue = match ($sortBy) {
                'name' => (string) ($left['name'] ?? ''),
                'family_name' => (string) ($left['family_name'] ?? ''),
                'bcc_name' => (string) ($left['bcc_name'] ?? ''),
                default => (string) ($left['event_date'] ?? ''),
            };
            $rightValue = match ($sortBy) {
                'name' => (string) ($right['name'] ?? ''),
                'family_name' => (string) ($right['family_name'] ?? ''),
                'bcc_name' => (string) ($right['bcc_name'] ?? ''),
                default => (string) ($right['event_date'] ?? ''),
            };

            $cmp = strcmp($leftValue, $rightValue);
            if ($cmp === 0) {
                $cmp = strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }

            return $cmp * $direction;
        });

        return $items;
    }

    /**
     * @return array<string, string|null>
     */
    private function familyContext(FamilyMember $member): array
    {
        $family = $member->family;

        return [
            'family_name' => $family?->family_name,
            'family_code' => $family?->family_code,
            'bcc_id' => $family?->bcc_id,
            'bcc_name' => $family?->bcc?->name,
        ];
    }
}
