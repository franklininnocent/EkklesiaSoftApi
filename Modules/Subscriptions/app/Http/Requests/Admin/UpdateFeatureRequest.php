<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\Feature;

class UpdateFeatureRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'module_key' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'feature_type' => ['sometimes', Rule::in(Feature::TYPES)],
            'unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'tier_options' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tier_options.*' => ['string', 'max:40', 'distinct'],
        ];
    }
}
