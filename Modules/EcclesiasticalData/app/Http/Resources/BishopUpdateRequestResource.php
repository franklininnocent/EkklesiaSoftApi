<?php

namespace Modules\EcclesiasticalData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;

/** @mixin BishopUpdateRequest */
class BishopUpdateRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $uploadService = app(BishopFileUploadService::class);
        $user = $request->user();
        $isReviewer = $user?->hasEkklesiaRole()
            && $user->can('viewInternalNotes', $this->resource);

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'diocese_id' => $this->diocese_id,
            'target_bishop_id' => $this->target_bishop_id,
            'request_type' => $this->request_type?->value ?? $this->request_type,
            'proposed_bishop_data' => $this->proposed_bishop_data,
            'pending_photo_public_url' => $uploadService->publicUrl(
                is_string($this->proposed_bishop_data['pending_photo_path'] ?? null)
                    ? $this->proposed_bishop_data['pending_photo_path']
                    : null
            ),
            'proposed_appointment_data' => $this->proposed_appointment_data,
            'submitted_by' => $this->submitted_by,
            'submitted_by_user' => $this->whenLoaded('submittedBy', fn () => [
                'id' => $this->submittedBy?->id,
                'name' => $this->submittedBy?->name,
            ]),
            'reviewer' => $this->whenLoaded('reviewer', fn () => [
                'id' => $this->reviewer?->id,
                'name' => $this->reviewer?->name,
            ]),
            'supporting_information' => $this->supporting_information,
            'source_reference' => $this->source_reference,
            'submission_notes' => $this->submission_notes,
            'status' => $this->status?->value ?? $this->status,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewer_comments' => $this->reviewer_comments,
            'submitter_feedback' => $this->submitter_feedback,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'version' => $this->version,
            'is_editable' => $this->isEditableBySubmitter(),
            'internal_reviewer_notes' => $this->when($isReviewer, $this->internal_reviewer_notes),
            'diocese' => $this->whenLoaded('diocese', fn () => [
                'id' => $this->diocese?->id,
                'name' => $this->diocese?->name,
            ]),
            'target_bishop' => $this->whenLoaded('targetBishop', function () use ($uploadService) {
                if (! $this->targetBishop) {
                    return null;
                }

                return [
                    'id' => $this->targetBishop->id,
                    'full_name' => $this->targetBishop->full_name,
                    'photo_public_url' => $uploadService->publicUrl($this->targetBishop->photo_path)
                        ?? $this->targetBishop->photo_url,
                ];
            }),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant?->id,
                'name' => $this->tenant?->name,
            ]),
        ];
    }
}
