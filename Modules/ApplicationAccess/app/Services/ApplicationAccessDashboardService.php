<?php

namespace Modules\ApplicationAccess\Services;

use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;

class ApplicationAccessDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $now = now();
        $lastFifteenMinutes = $now->copy()->subMinutes(15);
        $todayUtcStart = $now->copy()->utc()->startOfDay();
        $activeCutoff = $now->copy()->subHours(6);

        $activeSessions = ApplicationAccessSession::query()
            ->where('status', ApplicationAccessEnums::SESSION_ACTIVE)
            ->whereNull('ended_at')
            ->where('last_activity_at', '>=', $activeCutoff)
            ->count();

        $failedSignIns15m = ApplicationSecurityEvent::query()
            ->where('event_type', 'LOGIN_FAILURE')
            ->where('detected_at', '>=', $lastFifteenMinutes)
            ->count();

        $blockedAttempts15m = ApplicationAccessEvent::query()
            ->where('authorization_result', 'denied')
            ->where('occurred_at', '>=', $lastFifteenMinutes)
            ->count();

        $supportSessions = ApplicationAccessSession::query()
            ->whereNotNull('support_session_id')
            ->whereNull('ended_at')
            ->distinct()
            ->count('support_session_id');

        $needsAttention = ApplicationSecurityEvent::query()
            ->whereIn('severity', ['HIGH', 'CRITICAL'])
            ->where('detected_at', '>=', $lastFifteenMinutes)
            ->count();

        $failedSignInsToday = ApplicationSecurityEvent::query()
            ->where('event_type', 'LOGIN_FAILURE')
            ->where('detected_at', '>=', $todayUtcStart)
            ->count();

        $blockedAttemptsToday = ApplicationAccessEvent::query()
            ->where('authorization_result', 'denied')
            ->where('occurred_at', '>=', $todayUtcStart)
            ->count();

        return [
            'generated_at' => $now->toIso8601String(),
            'windows' => [
                'last_15_minutes' => [
                    'label' => 'Last 15 minutes',
                    'starts_at' => $lastFifteenMinutes->toIso8601String(),
                    'ends_at' => $now->toIso8601String(),
                ],
                'today_utc' => [
                    'label' => 'Today (UTC)',
                    'starts_at' => $todayUtcStart->toIso8601String(),
                    'ends_at' => $now->toIso8601String(),
                ],
            ],
            'kpis' => [
                'active_sessions' => $activeSessions,
                'failed_sign_ins_15m' => $failedSignIns15m,
                'blocked_attempts_15m' => $blockedAttempts15m,
                'support_sessions' => $supportSessions,
                'needs_attention_15m' => $needsAttention,
                'failed_sign_ins_today_utc' => $failedSignInsToday,
                'blocked_attempts_today_utc' => $blockedAttemptsToday,
            ],
        ];
    }
}
