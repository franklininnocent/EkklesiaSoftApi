<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDonationCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'code' => ['sometimes', 'required', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'is_tax_deductible' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
