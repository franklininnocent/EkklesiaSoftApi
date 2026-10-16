<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRecurringDonationScheduleRequest extends FormRequest
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
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'max:10'],
            'frequency' => ['sometimes', 'required', 'in:weekly,monthly,quarterly,yearly'],
            'next_run_on' => ['sometimes', 'required', 'date'],
            'end_on' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,paused,cancelled'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
