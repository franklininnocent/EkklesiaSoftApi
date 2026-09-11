<?php

namespace Modules\ApplicationAccess\Services;

use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;

class ApplicationSecuritySignalService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertMinuteWindow(
        string $signalType,
        ?string $sourceIp,
        array $attributes = [],
    ): ?ApplicationSecuritySignal {
        if (! (bool) config('applicationaccess.telemetry_enabled', false)) {
            return null;
        }

        if ($sourceIp === null || $sourceIp === '') {
            return null;
        }

        $now = now();
        $windowStart = $now->copy()->startOfMinute();
        $windowEnd = $windowStart->copy()->endOfMinute();

        $signal = ApplicationSecuritySignal::query()->firstOrNew([
            'signal_type' => $signalType,
            'source_ip' => $sourceIp,
            'window_start' => $windowStart,
        ]);

        if (! $signal->exists) {
            $signal->fill(array_merge([
                'window_end' => $windowEnd,
                'event_count' => 1,
                'first_seen' => $now,
                'last_seen' => $now,
                'risk_level' => $attributes['risk_level'] ?? 'MEDIUM',
                'risk_score' => $attributes['risk_score'] ?? 0,
            ], $attributes));
            $signal->save();

            return $signal;
        }

        $signal->increment('event_count');
        $signal->forceFill(array_merge([
            'window_end' => $windowEnd,
            'last_seen' => $now,
        ], $attributes))->save();

        return $signal->refresh();
    }

    public function recordInvalidToken(?string $sourceIp): void
    {
        $threshold = (int) config('applicationaccess.flood.invalid_token_per_minute', 20);
        $signal = $this->upsertMinuteWindow('INVALID_TOKEN', $sourceIp, ['risk_level' => 'MEDIUM']);

        if ($signal && $signal->event_count >= $threshold) {
            $signal->forceFill(['risk_level' => 'HIGH'])->save();
        }
    }

    public function recordRepeated403(?string $sourceIp, ?string $normalizedRoute = null): void
    {
        $signal = $this->upsertMinuteWindow('REPEATED_403', $sourceIp, ['risk_level' => 'MEDIUM']);
        if (! $signal) {
            return;
        }

        if ($normalizedRoute) {
            $routes = $signal->unique_routes ?? [];
            if (! in_array($normalizedRoute, $routes, true) && count($routes) < 20) {
                $routes[] = $normalizedRoute;
                $signal->forceFill(['unique_routes' => $routes])->save();
            }
        }
    }

    public function recordAnonymousFlood(?string $sourceIp): void
    {
        $threshold = (int) config('applicationaccess.flood.anonymous_events_per_minute', 30);
        $signal = $this->upsertMinuteWindow(
            'HIGH_VOLUME_UNAUTHENTICATED_ACTIVITY',
            $sourceIp,
            ['risk_level' => 'HIGH']
        );

        if ($signal && $signal->event_count >= $threshold) {
            $signal->forceFill(['risk_level' => 'CRITICAL'])->save();
        }
    }

    public function recordRepeatedLoginFailures(?string $sourceIp): void
    {
        $this->upsertMinuteWindow('REPEATED_LOGIN_FAILURES', $sourceIp, ['risk_level' => 'HIGH']);
    }
}
