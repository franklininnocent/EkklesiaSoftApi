<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_id' => ['nullable', 'uuid'],
            'family_member_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:60'],
            'donor_type' => ['nullable', 'in:family,individual,external,organization'],
            'is_anonymous' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
