<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('tenant_id');
        $this->offsetUnset('status');
        $this->offsetUnset('collected_amount');
    }

    public function rules(): array
    {
        return [
            'donor_id' => ['nullable', 'uuid'],
            'family_id' => ['nullable', 'uuid'],
            'family_member_id' => ['nullable', 'uuid'],
            'donation_category_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:180'],
            'pledged_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'received_at' => ['nullable', 'date'],
            'financial_year' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
            'is_anonymous' => ['nullable', 'boolean'],
        ];
    }
}
