<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Services\BccLeadershipService;

class AssignBccLeadershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_member_id' => ['required', 'uuid', 'exists:family_members,id'],
            'role' => ['required', 'string', Rule::in(BccLeadershipService::ROLES)],
            'role_description' => ['nullable', 'string', 'max:255'],
            'appointment_date' => ['required', 'date', 'before_or_equal:effective_from'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'term_label' => ['nullable', 'string', 'max:50'],
            'appointment_reference' => ['nullable', 'string', 'max:100'],
            'is_interim' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
            'leader_phone' => ['nullable', 'string', 'max:20'],
            'leader_email' => ['nullable', 'email', 'max:255'],
            'responsibilities' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $appointmentDate = $this->input(
            'appointment_date',
            $this->input('appointed_date', now()->toDateString())
        );

        $this->merge([
            'appointment_date' => $appointmentDate,
            'effective_from' => $this->input(
                'effective_from',
                $this->input('term_start_date', $appointmentDate)
            ),
            'effective_to' => $this->input('effective_to', $this->input('term_end_date')),
            'remarks' => $this->input('remarks', $this->input('notes')),
        ]);
    }
}
