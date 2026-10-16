<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignObligationsToCelebrationRequest extends FormRequest
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
            'obligation_ids.*' => ['required', 'uuid'],
            'date_variance_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
