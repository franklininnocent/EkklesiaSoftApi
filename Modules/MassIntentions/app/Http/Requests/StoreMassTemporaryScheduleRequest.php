<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMassTemporaryScheduleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'coverage_mode' => ['required', 'string', 'in:full_week,selected_weekdays'],
            'selected_weekdays' => ['nullable', 'array'],
            'selected_weekdays.*' => ['integer', 'min:0', 'max:6'],
        ];
    }
}
