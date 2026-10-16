<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMassScheduleProposalRequest extends FormRequest
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
            'celebrated_at' => ['required', 'string', 'max:16'],
            'place' => ['nullable', 'string', 'max:255'],
            'celebrant_name' => ['nullable', 'string', 'max:255'],
            'revision_id' => ['nullable', 'uuid'],
            'schedule_id' => ['nullable', 'uuid'],
            'source_label' => ['nullable', 'string', 'max:255'],
        ];
    }
}
