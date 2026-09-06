<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sacraments\Models\SacramentAuditLog;

/**
 * @mixin SacramentAuditLog
 */
class SacramentCertificateDownloadHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $actor = $this->relationLoaded('actor') ? $this->actor : null;
        $roleName = null;
        if ($actor && $actor->relationLoaded('role') && $actor->role) {
            $roleName = $actor->role->name;
        }

        return [
            'downloaded_at' => optional($this->created_at)?->toIso8601String(),
            'user_name' => $actor?->name,
            'role' => $roleName,
            'version' => (int) ($metadata['version'] ?? 0),
            'template_version' => $metadata['template_version'] ?? null,
            'ip_address' => $metadata['ip_address'] ?? null,
            'device_snapshot' => $metadata['device_snapshot'] ?? null,
        ];
    }
}
