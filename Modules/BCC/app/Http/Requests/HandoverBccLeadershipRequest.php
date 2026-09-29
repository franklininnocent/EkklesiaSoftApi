<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Services\BccLeadershipService;

class HandoverBccLeadershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outgoing_leader_id' => ['required', 'uuid'],
            'incoming_family_member_id' => ['required', 'uuid', 'exists:family_members,id'],
            'role' => ['nullable', 'string', Rule::in(BccLeadershipService::ROLES)],
            'outgoing_effective_to' => ['required', 'date', 'before_or_equal:today'],
            'outgoing_exit_reason' => ['required', 'string', Rule::in(BccLeadershipService::EXIT_REASONS)],
            'appointment_date' => ['required', 'date', 'before_or_equal:effective_from'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'term_label' => ['nullable', 'string', 'max:50'],
            'appointment_reference' => ['nullable', 'string', 'max:100'],
            'is_interim' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string'],
            'responsibilities' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $effectiveOn = $this->input('effective_on', now()->toDateString());
        $appointmentDate = $this->input('appointment_date', $effectiveOn);

        $this->merge([
            'outgoing_effective_to' => $this->input(
                'outgoing_effective_to',
                $this->input('effective_on', $effectiveOn)
            ),
            'outgoing_exit_reason' => $this->input('outgoing_exit_reason', 'handover'),
            'appointment_date' => $appointmentDate,
            'effective_from' => $this->input('effective_from', $effectiveOn),
            'effective_to' => $this->input('effective_to'),
            'remarks' => $this->input('remarks', $this->input('responsibilities')),
        ]);
    }
}
