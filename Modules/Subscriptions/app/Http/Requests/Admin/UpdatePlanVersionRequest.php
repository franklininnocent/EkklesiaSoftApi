<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\PlanVersion;

class UpdatePlanVersionRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'monthly_price' => ['sometimes', 'nullable', self::MONEY_REGEX],
            'annual_price' => ['sometimes', 'nullable', self::MONEY_REGEX],
            'setup_fee' => ['sometimes', 'nullable', self::MONEY_REGEX],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'tax_rate_percent' => ['sometimes', 'nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'billing_intervals' => ['sometimes', 'array', 'min:1'],
            'billing_intervals.*' => ['string', Rule::in(PlanVersion::INTERVALS)],
            'change_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
