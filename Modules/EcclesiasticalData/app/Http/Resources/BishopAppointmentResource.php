<?php

namespace Modules\EcclesiasticalData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;

/** @mixin BishopAppointment */
class BishopAppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $uploadService = app(BishopFileUploadService::class);

        return [
            'id' => $this->id,
            'bishop_id' => $this->bishop_id,
            'diocese_id' => $this->diocese_id,
            'ecclesiastical_title_id' => $this->ecclesiastical_title_id,
            'canonical_role' => $this->canonical_role?->value ?? $this->canonical_role,
            'appointed_date' => $this->appointed_date?->toDateString(),
            'announced_date' => $this->announced_date?->toDateString(),
            'effective_date' => ($this->effective_date ?? $this->appointed_date)?->toDateString(),
            'ordained_date' => $this->ordained_date?->toDateString(),
            'installed_date' => $this->installed_date?->toDateString(),
            'ended_date' => $this->ended_date?->toDateString(),
            'end_reason' => $this->end_reason?->value ?? $this->end_reason,
            'is_current' => (bool) $this->is_current,
            'appointment_status' => $this->appointment_status?->value ?? $this->appointment_status,
            'appointment_details' => $this->appointment_details,
            'metadata' => $this->metadata,
            'version' => $this->version,
            'source_type' => $this->source_type,
            'source_reference' => $this->source_reference,
            'bishop' => $this->whenLoaded('bishop', fn () => [
                'id' => $this->bishop?->id,
                'full_name' => $this->bishop?->full_name,
                'photo_url' => $this->bishop?->photo_url,
                'photo_path' => $this->bishop?->photo_path,
                'photo_public_url' => $uploadService->resolvePhotoUrl(
                    $this->bishop?->photo_path,
                    $this->bishop?->photo_url,
                ),
                'has_photo' => $uploadService->hasPhoto(
                    $this->bishop?->photo_path,
                    $this->bishop?->photo_url,
                ),
            ]),
            'diocese' => $this->whenLoaded('diocese', fn () => [
                'id' => $this->diocese?->id,
                'name' => $this->diocese?->name,
                'code' => $this->diocese?->code,
            ]),
            'ecclesiastical_title' => $this->whenLoaded('ecclesiasticalTitle', fn () => [
                'id' => $this->ecclesiasticalTitle?->id,
                'title' => $this->ecclesiasticalTitle?->title,
            ]),
        ];
    }
}
