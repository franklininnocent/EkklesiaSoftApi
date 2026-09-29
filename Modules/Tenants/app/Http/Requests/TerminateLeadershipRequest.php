<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\LeadershipExitReason;

class TerminateLeadershipRequest extends FormRequest
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
            'end_date' => ['required', 'date'],
            'exit_reason_code' => ['required', 'string', Rule::in(LeadershipExitReason::all())],
            'exit_reason_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
