<?php

namespace Modules\Subscriptions\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SubmitOfflineClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'billing_invoice_id' => ['nullable', 'integer'],
            'billing_order_id' => ['nullable', 'integer'],
            'method_code' => ['required', 'string', 'max:64'],
            'claimed_amount' => ['required', 'numeric', 'min:0.01'],
            'currency_code' => ['required', 'string', 'size:3'],
            'paid_on' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'remitter_name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
