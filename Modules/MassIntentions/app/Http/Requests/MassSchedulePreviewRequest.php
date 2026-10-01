<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MassSchedulePreviewRequest extends FormRequest
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
            'apply_from' => ['required', 'date'],
            'until' => ['nullable', 'date', 'after_or_equal:apply_from'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:apply_from'],
        ];
    }
}
