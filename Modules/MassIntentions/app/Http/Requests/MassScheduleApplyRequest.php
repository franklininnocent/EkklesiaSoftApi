<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MassScheduleApplyRequest extends FormRequest
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
            'fingerprint' => ['required', 'string', 'size:64'],
            'apply_from' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:apply_from'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:apply_from'],
        ];
    }
}
