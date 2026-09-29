<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\LeadershipExitReason;

class HandoverLeadershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outgoing_assignment_id' => ['required', 'uuid'],
            'outgoing_end_date' => ['required', 'date'],
            'outgoing_exit_reason_code' => ['required', 'string', Rule::in(LeadershipExitReason::all())],
            'outgoing_exit_reason_note' => ['nullable', 'string', 'max:2000'],
            'person_id' => ['required', 'uuid'],
            'role_id' => ['nullable', 'uuid'],
            'appointment_date' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'jurisdiction_name' => ['nullable', 'string', 'max:255'],
            'appointment_letter_ref' => ['nullable', 'string', 'max:150'],
        ];
    }
}
