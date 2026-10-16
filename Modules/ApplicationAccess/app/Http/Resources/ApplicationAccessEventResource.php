<?php

namespace Modules\ApplicationAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationAccessEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'access_session_id' => $this->access_session_id,
            'user_id' => $this->user_id,
            'ip_address' => $this->ip_address,
            'event_type' => $this->event_type,
            'module_code' => $this->module_code,
            'feature_code' => $this->feature_code,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'action' => $this->action,
            'route_name' => $this->route_name,
            'normalized_route' => $this->normalized_route,
            'http_method' => $this->http_method,
            'http_status' => $this->http_status,
            'authorization_result' => $this->authorization_result,
            'permission_code' => $this->permission_code,
            'tenant_id' => $this->tenant_id,
            'support_session_id' => $this->support_session_id,
            'request_id' => $this->request_id,
            'telemetry_source' => $this->telemetry_source,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'risk_level' => $this->risk_level,
            'metadata' => $this->metadata,
        ];
    }
}
