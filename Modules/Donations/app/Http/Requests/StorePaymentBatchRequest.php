<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_date' => ['required', 'date'],
            'source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'in:draft,posted,cancelled'],
            'notes' => ['nullable', 'string'],
            'payment_ids' => ['nullable', 'array'],
            'payment_ids.*' => ['required_with:payment_ids', 'uuid'],
        ];
    }
}
