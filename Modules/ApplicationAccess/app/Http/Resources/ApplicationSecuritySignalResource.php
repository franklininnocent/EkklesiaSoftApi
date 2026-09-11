<?php

namespace Modules\ApplicationAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationSecuritySignalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'signal_type' => $this->signal_type,
            'source_ip' => $this->source_ip,
            'window_start' => $this->window_start?->toIso8601String(),
            'window_end' => $this->window_end?->toIso8601String(),
            'event_count' => $this->event_count,
            'unique_routes' => $this->unique_routes,
            'status_counts' => $this->status_counts,
            'first_seen' => $this->first_seen?->toIso8601String(),
            'last_seen' => $this->last_seen?->toIso8601String(),
            'risk_level' => $this->risk_level,
            'risk_score' => $this->risk_score,
        ];
    }
}
