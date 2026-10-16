<?php

namespace Modules\Subscriptions\Http\Requests\Admin;

/**
 * Reject / request-more-information: the church sees this note, so it is required.
 */
class ReviewNoteRequest extends SubscriptionAdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'min:3', 'max:1000']];
    }
}
