<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sacraments\Support\MarriagePreparationStatus;

class UpdateMarriagePreparationCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bcc_id' => ['sometimes', 'nullable', 'uuid'],
            'bride_family_member_id' => ['sometimes', 'nullable', 'uuid'],
            'groom_family_member_id' => ['sometimes', 'nullable', 'uuid'],
            'sacrament_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', MarriagePreparationStatus::validationRule()],
            'pre_cana_completed_at' => ['sometimes', 'nullable', 'date'],
            'banns_published_at' => ['sometimes', 'nullable', 'date'],
            'canonical_docs_verified_at' => ['sometimes', 'nullable', 'date'],
            'intended_marriage_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
