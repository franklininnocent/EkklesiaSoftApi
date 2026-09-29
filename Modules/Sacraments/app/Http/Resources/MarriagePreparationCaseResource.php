<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sacraments\Models\MarriagePreparationCase;

/** @mixin MarriagePreparationCase */
class MarriagePreparationCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bcc_id' => $this->bcc_id,
            'bride_family_member_id' => $this->bride_family_member_id,
            'groom_family_member_id' => $this->groom_family_member_id,
            'sacrament_id' => $this->sacrament_id,
            'status' => $this->status,
            'inquiry_started_at' => $this->inquiry_started_at?->toIso8601String(),
            'pre_cana_completed_at' => $this->pre_cana_completed_at?->toIso8601String(),
            'banns_published_at' => $this->banns_published_at?->toIso8601String(),
            'canonical_docs_verified_at' => $this->canonical_docs_verified_at?->toIso8601String(),
            'intended_marriage_date' => $this->intended_marriage_date?->toDateString(),
            'bride' => $this->whenLoaded('brideFamilyMember', fn () => [
                'id' => $this->brideFamilyMember?->id,
                'name' => trim(($this->brideFamilyMember?->first_name ?? '').' '.($this->brideFamilyMember?->last_name ?? '')),
            ]),
            'groom' => $this->whenLoaded('groomFamilyMember', fn () => [
                'id' => $this->groomFamilyMember?->id,
                'name' => trim(($this->groomFamilyMember?->first_name ?? '').' '.($this->groomFamilyMember?->last_name ?? '')),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
