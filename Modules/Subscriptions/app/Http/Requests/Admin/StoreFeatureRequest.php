<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Modules\Subscriptions\Models\Feature;

class StoreFeatureRequest extends SubscriptionAdminRequest
{
    /**
     * is_core and legacy_key are system-owned and intentionally not accepted.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', self::CODE_REGEX],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'module_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'feature_type' => ['required', Rule::in(Feature::TYPES)],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_public' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'tier_options' => ['nullable', 'array', 'max:20', 'required_if:feature_type,'.Feature::TYPE_TIER],
            'tier_options.*' => ['string', 'max:40', 'distinct'],
        ];
    }
}
