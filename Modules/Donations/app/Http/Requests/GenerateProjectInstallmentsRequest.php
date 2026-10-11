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
            'mode' => ['nullable', 'in:generate,regenerate'],
            'confirm' => ['exclude_unless:mode,regenerate', 'required', 'boolean', 'accepted'],
            'reason' => ['exclude_unless:mode,regenerate', 'required', 'string', 'min:10', 'max:500'],
            'family_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'family_ids.*' => ['required', 'uuid'],
        ];
    }
}
