<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_id' => ['nullable', 'uuid'],
            'donor_id' => ['nullable', 'uuid'],
            'is_anonymous' => ['nullable', 'boolean'],
            'payer_name' => ['required', 'string', 'max:180'],
            'payer_email' => ['nullable', 'email', 'max:180'],
            'payer_phone' => ['nullable', 'string', 'max:60'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'method' => ['required', 'in:cash,bank_transfer,cheque,online_placeholder,adjustment'],
            'gateway_reference' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:pending,succeeded,failed,reversed,refunded'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.allocatable_type' => ['required_with:allocations', 'in:fund,plan,project,project_installment,due,donation,advance'],
            'allocations.*.allocatable_id' => ['required_with:allocations', 'uuid'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0.01'],
            'allocations.*.notes' => ['nullable', 'string'],
        ];
    }
}
