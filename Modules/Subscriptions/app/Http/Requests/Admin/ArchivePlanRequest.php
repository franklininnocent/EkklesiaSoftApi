<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

class ArchivePlanRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->reasonRules(false) + [
            'confirm_assigned_churches' => ['sometimes', 'boolean'],
        ];
    }
}
