<?php

namespace Modules\ApplicationAccess\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ApplicationAccess\Support\ApplicationAccessAuthorization;
use Modules\ApplicationAccess\Support\IdentifierMasker;
use Modules\Authentication\Models\User;

class ApplicationAccessSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canInvestigate = $this->canInvestigate($request);

        return [
            'id' => $this->id,
            'session_reference' => $this->session_reference,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () use ($canInvestigate) {
                if (! $this->user) {
                    return null;
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $canInvestigate
                        ? $this->user->email
                        : IdentifierMasker::maskEmail($this->user->email),
                    'tenant_id' => $this->user->tenant_id,
                ];
            }),
            'tenant_id' => $this->tenant_id,
            'role_id' => $this->role_id,
            'support_session_id' => $this->support_session_id,
            'identity_type' => $this->identity_type,
            'access_context' => $this->access_context,
            'authentication_status' => $this->authentication_status,
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end_reason' => $this->end_reason,
            'ip_address' => $this->ip_address,
            'ip_version' => $this->ip_version,
            'ip_class' => $this->ip_class,
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'geo_status' => $this->geo_status,
            'browser' => $this->browser,
            'browser_version' => $this->browser_version,
            'operating_system' => $this->operating_system,
            'device_type' => $this->device_type,
            'user_agent' => $this->user_agent,
            'risk_level' => $this->risk_level,
            'risk_score' => $this->risk_score,
            'previous_session_id' => $this->previous_session_id,
        ];
    }

    private function canInvestigate(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && ApplicationAccessAuthorization::hasAny($user, ['application_access.investigate']);
    }
}
