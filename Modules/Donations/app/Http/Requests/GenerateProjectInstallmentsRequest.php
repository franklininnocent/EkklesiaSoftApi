<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateProjectInstallmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_ids' => ['nullable', 'array', 'min:1'],
            'family_ids.*' => ['required', 'uuid'],
        ];
    }
}
