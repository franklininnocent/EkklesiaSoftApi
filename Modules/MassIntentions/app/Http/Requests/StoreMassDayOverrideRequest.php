<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMassDayOverrideRequest extends FormRequest
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
            'override_on' => ['required', 'date_format:Y-m-d'],
            'mode' => ['required', Rule::in(['replace', 'supplement'])],
            'closes_regular_masses' => ['sometimes', 'boolean'],
            'label' => ['nullable', 'string', 'max:120'],
            'slots' => ['array'],
            'slots.*.slot_id' => ['nullable', 'uuid'],
            'slots.*.celebrated_at' => ['required_with:slots', 'string', 'max:8'],
            'slots.*.place' => ['nullable', 'string', 'max:255'],
            'slots.*.celebrant_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
