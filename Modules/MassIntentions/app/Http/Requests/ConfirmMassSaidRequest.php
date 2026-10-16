<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmMassSaidRequest extends FormRequest
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
            'obligation_ids' => ['required', 'array', 'min:1'],
            'obligation_ids.*' => ['uuid'],
            'celebrant_overrides' => ['nullable', 'array'],
            'celebrant_overrides.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
