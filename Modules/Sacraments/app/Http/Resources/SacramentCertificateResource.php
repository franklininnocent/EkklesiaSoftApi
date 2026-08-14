<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never expose raw filesystem storage_key to clients (ADR-09).
 */
class SacramentCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sacrament_id' => $this->sacrament_id,
            'certificate_number' => $this->certificate_number,
            'certificate_type' => $this->certificate_type,
            'status' => $this->status,
            'version' => $this->version,
            'language' => $this->language,
            'locale' => $this->locale,
            'template_code' => $this->template_code,
            'template_version' => $this->template_version,
            'issued_at' => optional($this->issued_at)?->toIso8601String(),
            'issued_by' => $this->issued_by,
            'checksum' => $this->checksum,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'has_file' => ! empty($this->storage_key),
            'projection' => $this->when(
                $this->status === 'draft_preview' || $request->boolean('include_projection'),
                $this->projection_json
            ),
            'supersedes_certificate_id' => $this->supersedes_certificate_id,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
