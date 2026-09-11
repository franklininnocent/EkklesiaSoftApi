<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\Authentication\Models\User;

class ApplicationAccessInvestigationAudit
{
    public function recordSessionOpen(User $actor, ApplicationAccessSession $session): void
    {
        $cacheKey = 'application_access:investigation_open:'.$actor->id.':'.$session->id;
        $ttlSeconds = (int) config('applicationaccess.investigation_audit_ttl_seconds', 21600);

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, $ttlSeconds);

        app(ApplicationAccessRecorder::class)->recordSecurityEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'PRIVILEGED_OPERATION',
            'severity' => 'LOW',
            'actor_user_id' => $actor->id,
            'tenant_id' => $session->tenant_id,
            'access_session_id' => $session->id,
            'resource_type' => 'application_access_session',
            'resource_id' => $session->id,
            'action' => 'VIEW',
            'authorization_result' => 'allowed',
            'reason_code' => 'investigation_open',
            'detected_at' => now(),
            'metadata' => [
                'target_user_id' => $session->user_id,
            ],
        ]);
    }
}
