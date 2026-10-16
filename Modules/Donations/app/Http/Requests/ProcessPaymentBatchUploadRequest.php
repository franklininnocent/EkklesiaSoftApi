<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentBatchUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'commit' => ['nullable', 'boolean'],
            'batch_date' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.family_id' => ['nullable', 'uuid'],
            'rows.*.payer_name' => ['required', 'string', 'max:180'],
            'rows.*.payer_email' => ['nullable', 'email', 'max:180'],
            'rows.*.payment_date' => ['required', 'date'],
            'rows.*.amount' => ['required', 'numeric', 'min:0.01'],
            'rows.*.method' => ['required', 'in:cash,bank_transfer,cheque,online_placeholder,adjustment'],
            'rows.*.notes' => ['nullable', 'string'],
            'rows.*.allocations' => ['nullable', 'array'],
            'rows.*.allocations.*.allocatable_type' => ['required_with:rows.*.allocations', 'in:fund,plan,project,project_installment,due,donation,advance'],
            'rows.*.allocations.*.allocatable_id' => ['required_with:rows.*.allocations', 'uuid'],
            'rows.*.allocations.*.amount' => ['required_with:rows.*.allocations', 'numeric', 'min:0.01'],
        ];
    }
}
