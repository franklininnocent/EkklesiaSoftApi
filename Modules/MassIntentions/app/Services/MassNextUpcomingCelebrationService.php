<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Support\MassGenerationStatus;

/**
 * Resolves scheduled Masses whose start instant is strictly after parish "now".
 */
final class MassNextUpcomingCelebrationService
{
    /**
     * @return array{id: string, celebrated_on: string, celebrated_at: string|null, starts_at: string, parish_timezone: string}|null
     */
    public function resolve(int $tenantId): ?array
    {
        $celebration = $this->upcomingCelebrationsQuery($tenantId)
            ->orderBy('celebrated_on')
            ->orderBy('celebrated_at')
            ->orderBy('id')
            ->first(['id', 'celebrated_on', 'celebrated_at', 'tenant_id']);

        return $celebration === null ? null : $this->toUpcomingPayload($celebration, $tenantId);
    }

    /**
     * Next celebration plus additional upcoming rows from a single query.
     *
     * @return array{next: array<string, mixed>|null, upcoming: Collection<int, MassCelebration>}
     */
    public function nextAndUpcoming(int $tenantId, int $upcomingLimit = 3): array
    {
        $limit = max(1, $upcomingLimit) + 1;
        $rows = $this->listUpcomingCelebrations($tenantId, $limit);
        $nextModel = $rows->first();
        $next = $nextModel === null ? null : $this->toUpcomingPayload($nextModel, $tenantId);
        $upcoming = $nextModel === null
            ? $rows
            : $rows->slice(1)->values();

        return [
            'next' => $next,
            'upcoming' => $upcoming->take($upcomingLimit)->values(),
        ];
    }

    /**
     * @return array{id: string, celebrated_on: string, celebrated_at: string|null, starts_at: string, parish_timezone: string}
     */
    public function toUpcomingPayload(MassCelebration $celebration, int $tenantId): array
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $start = $this->startInstant($celebration, $timezone);

        return [
            'id' => (string) $celebration->id,
            'celebrated_on' => $celebration->celebrated_on?->format('Y-m-d') ?? DonationBusinessDate::today($tenantId),
            'celebrated_at' => $celebration->celebrated_at
                ? substr((string) $celebration->celebrated_at, 0, 5)
                : null,
            'starts_at' => $start->toIso8601String(),
            'parish_timezone' => $timezone,
        ];
    }

    /**
     * @return Collection<int, MassCelebration>
     */
    public function listUpcomingCelebrations(int $tenantId, int $limit = 8): Collection
    {
        return $this->upcomingCelebrationsQuery($tenantId)
            ->orderBy('celebrated_on')
            ->orderBy('celebrated_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function countUpcomingCelebrations(int $tenantId): int
    {
        return $this->upcomingCelebrationsQuery($tenantId)->count();
    }

    /**
     * @param  Builder<MassCelebration>  $query
     */
    public function applyUpcomingScheduleFilter(Builder $query, int $tenantId, string $columnPrefix = ''): void
    {
        $timezone = DonationBusinessDate::timezoneForTenant($tenantId);
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        $onColumn = $columnPrefix.'celebrated_on';
        $atColumn = $columnPrefix.'celebrated_at';

        $query->where(function ($outer) use ($onColumn, $atColumn, $today, $time): void {
            $outer->whereDate($onColumn, '>', $today)
                ->orWhere(function ($inner) use ($onColumn, $atColumn, $today, $time): void {
                    $inner->whereDate($onColumn, $today)
                        ->where($this->celebrationTimeAfter($atColumn, $time));
                });
        });
    }

    /**
     * Scheduled, active, non-suppressed celebrations that have not yet started (parish clock).
     *
     * @return Builder<MassCelebration>
     */
    public function upcomingCelebrationsQuery(int $tenantId): Builder
    {
        $query = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('generation_status', MassGenerationStatus::ACTIVE)
            ->where(function ($q): void {
                $q->whereNull('suppression_reason')
                    ->orWhere('suppression_reason', '');
            });

        $this->applyUpcomingScheduleFilter($query, $tenantId);

        return $query;
    }

    private function celebrationTimeAfter(string $atColumn, string $parishTime): \Closure
    {
        return function ($query) use ($atColumn, $parishTime): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->whereRaw(
                    "COALESCE({$atColumn}::time, '00:00:00'::time) > ?::time",
                    [$parishTime]
                );

                return;
            }

            $query->whereRaw(
                "TIME(COALESCE({$atColumn}, '00:00:00')) > TIME(?)",
                [$parishTime]
            );
        };
    }

    private function startInstant(MassCelebration $celebration, string $timezone): Carbon
    {
        $date = $celebration->celebrated_on?->format('Y-m-d') ?? DonationBusinessDate::today((int) $celebration->tenant_id);
        $time = $celebration->celebrated_at
            ? substr((string) $celebration->celebrated_at, 0, 8)
            : '00:00:00';

        return Carbon::parse("{$date} {$time}", $timezone);
    }
}
