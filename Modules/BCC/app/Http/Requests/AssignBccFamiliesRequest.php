<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignBccFamiliesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_ids' => ['required', 'array', 'min:1'],
            'family_ids.*' => ['required', 'uuid'],
            'transfer' => ['sometimes', 'boolean'],
            'joined_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
