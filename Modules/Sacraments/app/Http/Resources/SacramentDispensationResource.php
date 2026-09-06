<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SacramentDispensationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sacrament_id' => $this->sacrament_id,
            'dispensation_type' => $this->dispensation_type,
            'granting_authority' => $this->granting_authority,
            'protocol_number' => $this->protocol_number,
            'date_granted' => optional($this->date_granted)?->format('Y-m-d'),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
