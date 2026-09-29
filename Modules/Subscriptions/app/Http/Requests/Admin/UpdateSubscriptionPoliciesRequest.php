<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Services\SubscriptionPolicyService;

/**
 * Allowlisted policy keys only. There is intentionally no option to delete data on
 * downgrade or to disable core features.
 */
class UpdateSubscriptionPoliciesRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_plan_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_thresholds' => ['sometimes', 'array', 'min:1', 'max:6'],
            'usage_thresholds.*' => ['integer', 'min:1', 'max:100', 'distinct'],
            'over_limit_behavior' => ['sometimes', Rule::in([SubscriptionPolicyService::OVER_LIMIT_BLOCK_NEW, SubscriptionPolicyService::OVER_LIMIT_WARN_ONLY])],
            'limit_exempt_flows' => ['sometimes', 'array', 'max:10'],
            'limit_exempt_flows.*' => ['string', Rule::in(SubscriptionPolicyService::EXEMPTABLE_FLOWS)],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'trial' => ['sometimes', 'array:allow_trial_on_assignment,max_trial_days'],
            'trial.allow_trial_on_assignment' => ['sometimes', 'boolean'],
            'trial.max_trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'tax' => ['sometimes', 'array'],
            'tax.label' => ['sometimes', 'string', 'max:40'],
            'tax.rate_percent' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'min:0', 'max:100'],
            'tax.prices_include_tax' => ['sometimes', 'boolean'],
            'tax.jurisdiction' => ['sometimes', 'array'],
            'tax.jurisdiction.country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'tax.jurisdiction.tax_system' => ['sometimes', 'nullable', 'string', 'max:40'],
            'upgrade_requests' => ['sometimes', 'array:enabled'],
            'upgrade_requests.enabled' => ['sometimes', 'boolean'],
        ] + $this->reasonRules(false);
    }
}
