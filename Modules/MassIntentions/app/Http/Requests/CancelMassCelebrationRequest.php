<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelMassCelebrationRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:500'],
            'reassignments' => ['nullable', 'array'],
            'reassignments.*.obligation_id' => ['required_with:reassignments', 'uuid'],
            'reassignments.*.celebration_id' => ['nullable', 'uuid'],
        ];
    }
}
