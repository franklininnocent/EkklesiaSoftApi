<?php

namespace Modules\Tenants\Support;

use Illuminate\Support\Facades\Log;
use Modules\Tenants\Services\PlatformAuditLogger;

class SlowQueryLogger
{
    public static function register(): void
    {
        $thresholdMs = (int) config('tenants.platform.slow_query.threshold_ms', 0);

        if ($thresholdMs <= 0) {
            return;
        }

        \Illuminate\Support\Facades\DB::listen(function ($query) use ($thresholdMs): void {
            $durationMs = $query->time;

            if ($durationMs < $thresholdMs) {
                return;
            }

            $tenantId = null;
            try {
                $tenantId = app(TenantContext::class)->effectiveTenantId();
            } catch (\Throwable) {
                $tenantId = null;
            }

            $payload = [
                'duration_ms' => round($durationMs, 2),
                'connection' => $query->connectionName,
                'sql_hash' => sha1($query->sql),
                'tenant_id' => $tenantId,
            ];

            Log::channel(config('tenants.platform.slow_query.log_channel', 'platform'))->warning('slow_query', $payload);

            if (config('tenants.platform.slow_query.audit_log', false)) {
                app(PlatformAuditLogger::class)->record(
                    category: 'performance',
                    event: 'slow_query',
                    tenantId: $tenantId !== null ? (int) $tenantId : null,
                    metadata: $payload,
                );
            }
        });
    }
}
