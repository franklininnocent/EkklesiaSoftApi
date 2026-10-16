<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class PublishPlanVersionRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'effective_from' => ['nullable', 'date'],
        ] + $this->reasonRules(false);
    }
}
