<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\Plan;

class StorePlanRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', self::CODE_REGEX, Rule::notIn(['LEGACY_FREE', 'LEGACY_BASIC', 'LEGACY_PREMIUM', 'BASIC', 'PREMIUM'])],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'pricing_type' => ['required', Rule::in(Plan::PRICING_TYPES)],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_assignable' => ['sometimes', 'boolean'],
            'badge_label' => ['nullable', 'string', 'max:60'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
