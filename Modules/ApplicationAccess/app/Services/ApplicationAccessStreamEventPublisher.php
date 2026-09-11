<?php

namespace Modules\ApplicationAccess\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;

class ApplicationAccessStreamEventPublisher
{
    /**
     * @return list<array{stream_id: string, stream_type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    public function replayFromLastEventId(?string $lastEventId): array
    {
        if ($lastEventId === null || $lastEventId === '' || ! str_contains($lastEventId, ':')) {
            return [];
        }

        [$type, $id] = explode(':', $lastEventId, 2);
        $maxRows = max(1, (int) config('applicationaccess.sse.replay_max_rows', 100));
        $maxSeconds = max(1, (int) config('applicationaccess.sse.replay_max_seconds', 120));
        $cutoff = now()->subSeconds($maxSeconds);

        if ($type === 'access') {
            $anchor = ApplicationAccessEvent::query()->find($id);
            if (! $anchor || $anchor->occurred_at?->lt($cutoff)) {
                return [];
            }

            return $this->mapAccessEvents(
                ApplicationAccessEvent::query()
                    ->where('occurred_at', '>=', $anchor->occurred_at)
                    ->where(function ($query) use ($anchor): void {
                        $query->where('occurred_at', '>', $anchor->occurred_at)
                            ->orWhere('id', '>', $anchor->id);
                    })
                    ->orderBy('occurred_at')
                    ->orderBy('id')
                    ->limit($maxRows)
                    ->get()
            );
        }

        if ($type === 'security') {
            $anchor = ApplicationSecurityEvent::query()->find($id);
            if (! $anchor || $anchor->detected_at?->lt($cutoff)) {
                return [];
            }

            return $this->mapSecurityEvents(
                ApplicationSecurityEvent::query()
                    ->where('detected_at', '>=', $anchor->detected_at)
                    ->where(function ($query) use ($anchor): void {
                        $query->where('detected_at', '>', $anchor->detected_at)
                            ->orWhere('id', '>', $anchor->id);
                    })
                    ->orderBy('detected_at')
                    ->orderBy('id')
                    ->limit($maxRows)
                    ->get()
            );
        }

        return [];
    }

    /**
     * @return list<array{stream_id: string, stream_type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    public function fetchSince(Carbon $since, ?string $afterAccessId = null, ?string $afterSecurityId = null): array
    {
        $limit = max(1, (int) config('applicationaccess.sse.poll_batch_size', 50));

        $accessQuery = ApplicationAccessEvent::query()
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit);

        if ($afterAccessId) {
            $anchor = ApplicationAccessEvent::query()->find($afterAccessId);
            if ($anchor) {
                $accessQuery->where(function ($query) use ($anchor): void {
                    $query->where('occurred_at', '>', $anchor->occurred_at)
                        ->orWhere(function ($inner) use ($anchor): void {
                            $inner->where('occurred_at', $anchor->occurred_at)
                                ->where('id', '>', $anchor->id);
                        });
                });
            }
        }

        $securityQuery = ApplicationSecurityEvent::query()
            ->where('detected_at', '>=', $since)
            ->orderBy('detected_at')
            ->orderBy('id')
            ->limit($limit);

        if ($afterSecurityId) {
            $anchor = ApplicationSecurityEvent::query()->find($afterSecurityId);
            if ($anchor) {
                $securityQuery->where(function ($query) use ($anchor): void {
                    $query->where('detected_at', '>', $anchor->detected_at)
                        ->orWhere(function ($inner) use ($anchor): void {
                            $inner->where('detected_at', $anchor->detected_at)
                                ->where('id', '>', $anchor->id);
                        });
                });
            }
        }

        $events = collect($this->mapAccessEvents($accessQuery->get()))
            ->merge($this->mapSecurityEvents($securityQuery->get()))
            ->sortBy('occurred_at')
            ->values()
            ->all();

        return $events;
    }

    /**
     * @param  Collection<int, ApplicationAccessEvent>  $events
     * @return list<array{stream_id: string, stream_type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    private function mapAccessEvents(Collection $events): array
    {
        return $events->map(function (ApplicationAccessEvent $event): array {
            return [
                'stream_id' => 'access:'.$event->id,
                'stream_type' => 'access',
                'occurred_at' => $event->occurred_at?->toIso8601String() ?? now()->toIso8601String(),
                'payload' => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'action' => $event->action,
                    'user_id' => $event->user_id,
                    'tenant_id' => $event->tenant_id,
                    'access_session_id' => $event->access_session_id,
                    'authorization_result' => $event->authorization_result,
                    'normalized_route' => $event->normalized_route,
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ],
            ];
        })->all();
    }

    /**
     * @param  Collection<int, ApplicationSecurityEvent>  $events
     * @return list<array{stream_id: string, stream_type: string, occurred_at: string, payload: array<string, mixed>}>
     */
    private function mapSecurityEvents(Collection $events): array
    {
        return $events->map(function (ApplicationSecurityEvent $event): array {
            return [
                'stream_id' => 'security:'.$event->id,
                'stream_type' => 'security',
                'occurred_at' => $event->detected_at?->toIso8601String() ?? now()->toIso8601String(),
                'payload' => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'severity' => $event->severity,
                    'actor_user_id' => $event->actor_user_id,
                    'tenant_id' => $event->tenant_id,
                    'reason_code' => $event->reason_code,
                    'detected_at' => $event->detected_at?->toIso8601String(),
                ],
            ];
        })->all();
    }
}
