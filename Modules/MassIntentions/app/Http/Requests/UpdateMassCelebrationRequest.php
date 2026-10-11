<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMassCelebrationRequest extends FormRequest
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
            'celebrated_on' => ['sometimes', 'required', 'date'],
            'celebrated_at' => ['nullable', 'date_format:H:i'],
            'place' => ['nullable', 'string', 'max:255'],
            'celebrant_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
