<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string'],
            'is_tax_deductible' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
