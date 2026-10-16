<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sacraments\Support\SacramentAnnotationType;

class SacramentCanonicalAnnotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sacrament_id' => $this->sacrament_id,
            'annotation_type' => $this->annotation_type,
            'annotation_type_label' => SacramentAnnotationType::label($this->annotation_type),
            'effective_date' => optional($this->effective_date)?->format('Y-m-d'),
            'granting_authority' => $this->granting_authority,
            'protocol_number' => $this->protocol_number,
            'notes' => $this->notes,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
