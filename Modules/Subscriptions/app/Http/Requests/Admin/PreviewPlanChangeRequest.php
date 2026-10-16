<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class PreviewPlanChangeRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'min:1'],
            'custom_limits' => ['nullable', 'array', 'max:20'],
            'custom_limits.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
