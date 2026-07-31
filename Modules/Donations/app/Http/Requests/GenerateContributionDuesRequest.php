<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateContributionDuesRequest extends FormRequest
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
            'period_label' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'amount_due' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'use_current_period' => ['nullable', 'boolean'],
        ];
    }
}
