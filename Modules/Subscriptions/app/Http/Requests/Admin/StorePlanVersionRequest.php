<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class StorePlanVersionRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_version_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
