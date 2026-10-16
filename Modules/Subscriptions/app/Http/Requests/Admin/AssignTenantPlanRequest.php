<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\PlanVersion;

/**
 * The target tenant comes from the route (platform-authorised), never from the body.
 */
class AssignTenantPlanRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'min:1'],
            'billing_interval' => ['nullable', Rule::in(PlanVersion::INTERVALS)],
            'contracted_price' => ['nullable', self::MONEY_REGEX],
            'custom_limits' => ['nullable', 'array', 'max:20'],
            'custom_limits.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'start_trial' => ['sometimes', 'boolean'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'confirm_impact' => ['sometimes', 'boolean'],
        ] + $this->reasonRules(true);
    }

    protected function prepareForValidation(): void
    {
        $limits = $this->input('custom_limits');
        if (is_array($limits)) {
            $normalized = [];
            foreach ($limits as $code => $value) {
                $normalized[strtoupper((string) $code)] = $value === '' ? null : $value;
            }
            $this->merge(['custom_limits' => $normalized]);
        }
    }
}
