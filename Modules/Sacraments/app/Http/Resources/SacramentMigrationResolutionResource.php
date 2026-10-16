<?php

namespace Modules\Sacraments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SacramentMigrationResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'legacy_sacrament_id' => $this->legacy_sacrament_id,
            'participant_role' => $this->participant_role,
            'legacy_name' => $this->legacy_name,
            'legacy_dob' => optional($this->legacy_dob)?->format('Y-m-d'),
            'candidate_member_id' => $this->candidate_member_id,
            'candidate_member_name' => $this->whenLoaded(
                'candidateMember',
                fn () => $this->candidateMember?->full_name_display
            ),
            'confidence' => $this->confidence,
            'resolution' => $this->resolution,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => optional($this->resolved_at)?->toIso8601String(),
            'migration_key' => $this->migration_key,
            'sacrament' => $this->whenLoaded('legacySacrament', function () {
                $s = $this->legacySacrament;

                return $s ? [
                    'id' => $s->id,
                    'recipient_name' => $s->recipient_name,
                    'date_administered' => optional($s->date_administered)?->format('Y-m-d'),
                    'sacrament_type_id' => $s->sacrament_type_id,
                    'status' => $s->status,
                ] : null;
            }),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
