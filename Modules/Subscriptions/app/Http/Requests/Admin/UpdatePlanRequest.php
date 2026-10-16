<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\Plan;

class UpdatePlanRequest extends SubscriptionAdminRequest
{
    /**
     * code, key, status and legacy flags are not accepted: identity is immutable and
     * lifecycle changes go through dedicated publish/archive endpoints.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pricing_type' => ['sometimes', Rule::in(Plan::PRICING_TYPES)],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_assignable' => ['sometimes', 'boolean'],
            'badge_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'confirm_assignment_impact' => ['sometimes', 'boolean'],
        ];
    }
}
