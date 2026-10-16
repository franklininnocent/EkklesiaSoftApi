<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarriagePreparationCaseRequest extends FormRequest
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
            'bcc_id' => ['nullable', 'uuid'],
            'bride_family_member_id' => ['nullable', 'uuid'],
            'groom_family_member_id' => ['nullable', 'uuid'],
            'inquiry_started_at' => ['nullable', 'date'],
            'intended_marriage_date' => ['nullable', 'date'],
        ];
    }
}
