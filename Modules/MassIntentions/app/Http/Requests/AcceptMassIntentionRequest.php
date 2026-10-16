<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptMassIntentionRequest extends FormRequest
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
            'mass_count' => ['required', 'integer', 'min:1', 'max:99'],
            'duplicate_warning_acknowledged' => ['nullable', 'boolean'],
            'offering_amount' => ['nullable', 'numeric', 'min:0'],
            'offering_payment_method' => ['nullable', 'string', 'max:32'],
            'offering_received_on' => ['nullable', 'date'],
        ];
    }
}
