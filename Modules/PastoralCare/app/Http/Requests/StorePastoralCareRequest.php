<?php

namespace Modules\PastoralCare\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\PastoralCare\Support\PastoralCarePriority;
use Modules\PastoralCare\Support\PastoralCareType;

class StorePastoralCareRequest extends FormRequest
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
            'family_id' => ['required', 'uuid'],
            'person_id' => ['nullable', 'uuid'],
            'type' => ['required', 'in:'.implode(',', PastoralCareType::all())],
            'priority' => ['nullable', 'in:'.implode(',', PastoralCarePriority::all())],
            'summary' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date'],
        ];
    }
}
