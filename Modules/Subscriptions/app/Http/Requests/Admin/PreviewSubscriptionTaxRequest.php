<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class PreviewSubscriptionTaxRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', self::MONEY_REGEX],
            'tax' => ['sometimes', 'array'],
            'tax.rate_percent' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            'tax.prices_include_tax' => ['sometimes', 'boolean'],
        ];
    }
}
