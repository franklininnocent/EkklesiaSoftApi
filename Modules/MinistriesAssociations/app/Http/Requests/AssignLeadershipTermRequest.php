<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class AssignLeadershipTermRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'membership_id' => ['required', 'uuid', $this->tenantExists('ma_memberships')],
            'position_id' => ['required', 'uuid', $this->tenantExists('ma_positions')],
            'appointment_date' => ['required', 'date', 'before_or_equal:effective_from'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'term_label' => ['nullable', 'string', 'max:50'],
            'appointment_reference' => ['nullable', 'string', 'max:100'],
            'is_interim' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
