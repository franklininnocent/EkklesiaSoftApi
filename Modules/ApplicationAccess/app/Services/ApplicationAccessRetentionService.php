<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\ApplicationAccess\Support\ApplicationIpBlockMatcher;

class ApplicationAccessRetentionService
{
    /**
     * @return array{
     *     access_events: int,
     *     security_events: int,
     *     signals: int,
     *     sessions: int,
     *     ip_blocks_expired: int
     * }
     */
    public function run(bool $dryRun = false): array
    {
        $retention = (array) config('applicationaccess.retention', []);
        $accessCutoff = now()->subDays(max(1, (int) ($retention['access_events_days'] ?? 90)));
        $securityCutoff = now()->subDays(max(1, (int) ($retention['security_events_days'] ?? 180)));
        $signalsCutoff = now()->subDays(max(1, (int) ($retention['signals_days'] ?? 90)));
        $sessionsCutoff = now()->subDays(max(1, (int) ($retention['ended_sessions_days'] ?? 90)));

        $counts = [
            'access_events' => $this->countAccessEvents($accessCutoff),
            'security_events' => $this->countSecurityEvents($securityCutoff),
            'signals' => $this->countSignals($signalsCutoff),
            'sessions' => $this->countEndedSessions($sessionsCutoff),
            'ip_blocks_expired' => $this->countExpiredIpBlocks(),
        ];

        if ($dryRun) {
            return $counts;
        }

        ApplicationAccessEvent::query()
            ->where('occurred_at', '<', $accessCutoff)
            ->delete();

        ApplicationSecurityEvent::query()
            ->where('detected_at', '<', $securityCutoff)
            ->delete();

        ApplicationSecuritySignal::query()
            ->where('last_seen', '<', $signalsCutoff)
            ->delete();

        ApplicationAccessSession::query()
            ->where('status', '!=', ApplicationAccessEnums::SESSION_ACTIVE)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $sessionsCutoff)
            ->delete();

        $expiredBlocks = ApplicationIpBlockRule::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['revoked_at' => now()]);

        if ($expiredBlocks > 0) {
            ApplicationIpBlockMatcher::flushCache();
        }

        $this->recordRetentionRun($counts);

        return $counts;
    }

    /**
     * @param  array{
     *     access_events: int,
     *     security_events: int,
     *     signals: int,
     *     sessions: int,
     *     ip_blocks_expired: int
     * }  $counts
     */
    private function recordRetentionRun(array $counts): void
    {
        ApplicationSecurityEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'PRIVILEGED_OPERATION',
            'severity' => 'LOW',
            'action' => 'DELETE',
            'authorization_result' => 'allowed',
            'reason_code' => 'retention_run',
            'detected_at' => now(),
            'metadata' => $counts,
        ]);
    }

    private function countAccessEvents(\DateTimeInterface $cutoff): int
    {
        return ApplicationAccessEvent::query()
            ->where('occurred_at', '<', $cutoff)
            ->count();
    }

    private function countSecurityEvents(\DateTimeInterface $cutoff): int
    {
        return ApplicationSecurityEvent::query()
            ->where('detected_at', '<', $cutoff)
            ->count();
    }

    private function countSignals(\DateTimeInterface $cutoff): int
    {
        return ApplicationSecuritySignal::query()
            ->where('last_seen', '<', $cutoff)
            ->count();
    }

    private function countEndedSessions(\DateTimeInterface $cutoff): int
    {
        return ApplicationAccessSession::query()
            ->where('status', '!=', ApplicationAccessEnums::SESSION_ACTIVE)
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', $cutoff)
            ->count();
    }

    private function countExpiredIpBlocks(): int
    {
        return ApplicationIpBlockRule::query()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();
    }
}
