<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRecurringDonationScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'donor_id' => ['nullable', 'uuid'],
            'family_id' => ['nullable', 'uuid'],
            'donation_category_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'next_run_on' => ['required', 'date'],
            'end_on' => ['nullable', 'date', 'after_or_equal:next_run_on'],
            'status' => ['nullable', 'in:active,paused,cancelled'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
