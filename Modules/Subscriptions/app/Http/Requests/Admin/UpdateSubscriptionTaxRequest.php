<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Validator;

class UpdateSubscriptionTaxRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tax' => ['required', 'array'],
            'tax.label' => ['required', 'string', 'max:40'],
            'tax.rate_percent' => ['required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            'tax.prices_include_tax' => ['required', 'boolean'],
            'tax.jurisdiction' => ['sometimes', 'array'],
            'tax.jurisdiction.country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'tax.jurisdiction.tax_system' => ['sometimes', 'nullable', 'string', 'max:40'],
        ] + $this->reasonRules(false);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $rate = $this->input('tax.rate_percent');
            $label = trim((string) $this->input('tax.label', ''));
            if ($rate !== null && $rate !== '' && (float) $rate > 0 && $label === '') {
                $v->errors()->add('tax.label', 'Tax name is required when the rate is greater than zero.');
            }
        });
    }
}
