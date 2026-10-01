<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MassIntentions\Support\MassScheduleConstants;

class PutMassScheduleDraftRequest extends FormRequest
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
            'default_place' => ['nullable', 'string', 'max:255'],
            'default_celebrant_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'coverage_mode' => ['nullable', 'string', 'in:full_week,selected_weekdays'],
            'selected_weekdays' => ['nullable', 'array'],
            'selected_weekdays.*' => ['integer', 'min:0', 'max:6'],
            'slots' => ['nullable', 'array', 'max:'.MassScheduleConstants::MAX_SLOTS_PER_REVISION],
            'slots.*.slot_id' => ['nullable', 'uuid'],
            'slots.*.weekday' => ['required_with:slots', 'integer', 'min:0', 'max:6'],
            'slots.*.weeks_of_month' => ['nullable', 'array', 'max:5'],
            'slots.*.weeks_of_month.*' => ['string', 'in:1,2,3,4,last'],
            'slots.*.celebrated_at' => ['required_with:slots', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'slots.*.place' => ['nullable', 'string', 'max:255'],
            'slots.*.celebrant_name' => ['nullable', 'string', 'max:255'],
            'slots.*.place_source' => ['nullable', 'string', 'in:inherit,override,unset'],
            'slots.*.celebrant_source' => ['nullable', 'string', 'in:inherit,override,unset'],
        ];
    }
}
