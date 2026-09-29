<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\PlanVersion;

class ApproveUpgradeRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'billing_interval' => ['nullable', Rule::in(PlanVersion::INTERVALS)],
            'contracted_price' => ['nullable', self::MONEY_REGEX],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'confirm_impact' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
