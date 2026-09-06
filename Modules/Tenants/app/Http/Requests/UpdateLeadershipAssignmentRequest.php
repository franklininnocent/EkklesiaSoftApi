<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadershipAssignmentRequest extends FormRequest
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
            'role_id' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'appointment_date' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'jurisdiction_name' => ['nullable', 'string', 'max:255'],
            'appointment_letter_ref' => ['nullable', 'string', 'max:150'],
        ];
    }
}
