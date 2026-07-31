<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectVoluntaryDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'donation_id' => ['nullable', 'uuid'],
            'donor_id' => ['nullable', 'uuid'],
            'donor_name' => ['nullable', 'string', 'max:180'],
            'donor_email' => ['nullable', 'email', 'max:180'],
            'donor_phone' => ['nullable', 'string', 'max:60'],
            'donor_type' => ['nullable', 'in:family,individual,external,organization'],
            'family_id' => ['nullable', 'uuid'],
            'family_member_id' => ['nullable', 'uuid'],
            'donation_category_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:180'],
            'pledged_amount' => ['nullable', 'numeric', 'min:0'],
            'received_at' => ['nullable', 'date'],
            'financial_year' => ['nullable', 'string', 'max:20'],
            'is_anonymous' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'method' => ['required', 'in:cash,bank_transfer,cheque,online_placeholder,adjustment'],
            'payment_date' => ['required', 'date'],
            'payer_name' => ['nullable', 'string', 'max:180'],
            'payer_email' => ['nullable', 'email', 'max:180'],
            'payer_phone' => ['nullable', 'string', 'max:60'],
            'payment_notes' => ['nullable', 'string'],
        ];
    }
}
