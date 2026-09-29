<?php

namespace Modules\EcclesiasticalData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;

/** @mixin BishopManagement */
class BishopResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $uploadService = app(BishopFileUploadService::class);

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'normalized_name' => $this->normalized_name,
            'given_name' => $this->given_name,
            'family_name' => $this->family_name,
            'religious_name' => $this->religious_name,
            'archdiocese_id' => $this->archdiocese_id,
            'ecclesiastical_title_id' => $this->ecclesiastical_title_id,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'ordained_priest_date' => $this->ordained_priest_date?->toDateString(),
            'ordained_bishop_date' => $this->ordained_bishop_date?->toDateString(),
            'retired_date' => $this->retired_date?->toDateString(),
            'appointed_date' => $this->appointed_date?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'biography' => $this->biography,
            'education' => $this->education,
            'status' => $this->status,
            'is_current' => (bool) $this->is_current,
            'is_active' => (bool) ($this->active ?? false),
            'photo_public_url' => $uploadService->resolvePhotoUrl($this->photo_path, $this->photo_url),
            'has_photo' => $uploadService->hasPhoto($this->photo_path, $this->photo_url),
            'coat_of_arms_path' => $this->coat_of_arms_path,
            'coat_of_arms_public_url' => $uploadService->publicUrl($this->coat_of_arms_path),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'verification_notes' => $this->verification_notes,
            'metadata' => $this->metadata,
            'archdiocese' => $this->whenLoaded('archdiocese', fn () => [
                'id' => $this->archdiocese?->id,
                'name' => $this->archdiocese?->name,
            ]),
            'ecclesiastical_title' => $this->whenLoaded('ecclesiasticalTitle', fn () => [
                'id' => $this->ecclesiasticalTitle?->id,
                'title' => $this->ecclesiasticalTitle?->title,
            ]),
            'appointments' => BishopAppointmentResource::collection(
                $this->whenLoaded('appointments')
            ),
            'current_appointment' => $this->when(
                $this->relationLoaded('appointments'),
                function () {
                    $current = $this->resource->currentAppointment();

                    return $current ? new BishopAppointmentResource($current) : null;
                }
            ),
        ];
    }
}
