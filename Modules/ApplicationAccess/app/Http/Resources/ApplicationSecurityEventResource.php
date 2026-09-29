<?php

namespace Modules\ApplicationAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationSecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'risk_score' => $this->risk_score,
            'actor_user_id' => $this->actor_user_id,
            'tenant_id' => $this->tenant_id,
            'access_session_id' => $this->access_session_id,
            'support_session_id' => $this->support_session_id,
            'source_ip' => $this->source_ip,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'action' => $this->action,
            'authorization_result' => $this->authorization_result,
            'reason_code' => $this->reason_code,
            'request_id' => $this->request_id,
            'detected_at' => $this->detected_at?->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
