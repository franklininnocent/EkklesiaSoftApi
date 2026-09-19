<?php

namespace Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*.definition_code' => ['required', 'string', 'max:120'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
            'preferences.*.push' => ['sometimes', 'boolean'],
            'preferences.*.digest' => ['sometimes', 'string', 'in:immediate,daily,off'],
        ];
    }
}
