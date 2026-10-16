<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\TenantEntitlementOverride;

class GrantOverrideRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'feature_code' => ['required', 'string', self::CODE_REGEX],
            'mode' => ['required', Rule::in(TenantEntitlementOverride::MODES)],
            'numeric_value' => ['nullable', 'integer', 'min:0', 'max:100000000', 'required_if:mode,'.TenantEntitlementOverride::MODE_SET_LIMIT],
            'tier_value' => ['nullable', 'string', 'max:40', 'required_if:mode,'.TenantEntitlementOverride::MODE_SET_TIER],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:now'],
        ] + $this->reasonRules(true);
    }

    protected function prepareForValidation(): void
    {
        foreach (['feature_code', 'mode'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => strtoupper(trim($this->input($key)))]);
            }
        }
    }
}
