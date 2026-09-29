<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Tenants\Support\AuditPiiRedactor;

class PlatformAuditLogger
{
    public function __construct(
        private readonly AuditPiiRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $category,
        string $event,
        ?int $tenantId = null,
        ?int $actorUserId = null,
        ?int $httpStatus = null,
        ?string $requestMethod = null,
        ?string $requestPath = null,
        array $metadata = [],
    ): void {
        if (! config('tenants.platform.audit.enabled', true)) {
            return;
        }

        $payload = $this->redactor->redact($metadata);

        Log::channel(config('tenants.platform.audit.log_channel', 'platform'))->info($event, [
            'category' => $category,
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'http_status' => $httpStatus,
            'request_method' => $requestMethod,
            'request_path' => $requestPath,
            'metadata' => $payload,
        ]);

        try {
            DB::table('platform_audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'category' => $category,
                'event' => $event,
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'ip_address' => request()->ip(),
                'http_status' => $httpStatus,
                'request_method' => $requestMethod,
                'request_path' => $requestPath,
                'metadata' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('platform_audit_logs insert failed: '.$exception->getMessage());
        }
    }
}
