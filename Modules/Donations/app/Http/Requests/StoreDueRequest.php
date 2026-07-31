<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_id' => ['required', 'uuid'],
            'plan_id' => ['required', 'uuid'],
            'period_label' => ['required', 'string', 'max:50'],
            'due_date' => ['required', 'date'],
            'amount_due' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
