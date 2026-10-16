<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

/**
 * Lifecycle actions (archive, restore, retire, unschedule, revoke) that only carry a reason.
 */
class ReasonRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->reasonRules(false);
    }
}
