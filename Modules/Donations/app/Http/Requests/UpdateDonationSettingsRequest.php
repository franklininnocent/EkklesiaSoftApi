<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDonationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_currency' => ['required', 'string', 'max:10'],
            'financial_year_start_month' => ['required', 'string', 'size:2'],
            'financial_year_start_day' => ['required', 'string', 'size:2'],
            'tax_registration_number' => ['nullable', 'string', 'max:120'],
            'tax_acknowledgement_note' => ['nullable', 'string'],
            'receipt_prefix_enabled' => ['nullable', 'boolean'],
            'receipt_prefix' => ['nullable', 'string', 'max:20'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
