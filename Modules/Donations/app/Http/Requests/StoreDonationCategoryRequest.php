<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonationCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'is_tax_deductible' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
