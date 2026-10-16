<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Services\BccLeadershipService;

class UpdateBCCLeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_member_id' => ['sometimes', 'uuid', 'exists:family_members,id'],
            'role' => ['sometimes', 'string', Rule::in(BccLeadershipService::ROLES)],
            'role_description' => ['nullable', 'string', 'max:255'],
            'appointment_date' => ['sometimes', 'date', 'before_or_equal:effective_from'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'term_label' => ['nullable', 'string', 'max:50'],
            'appointment_reference' => ['nullable', 'string', 'max:100'],
            'is_interim' => ['sometimes', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'completed', 'vacated', 'terminated'])],
            'exit_reason' => ['nullable', 'string', Rule::in(BccLeadershipService::EXIT_REASONS)],
            'remarks' => ['nullable', 'string'],
            'leader_phone' => ['nullable', 'string', 'max:20'],
            'leader_email' => ['nullable', 'email', 'max:255'],
            'responsibilities' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->hasAny(['appointment_date', 'appointed_date'])) {
            $normalized['appointment_date'] = $this->input(
                'appointment_date',
                $this->input('appointed_date')
            );
        }
        if ($this->hasAny(['effective_from', 'term_start_date'])) {
            $normalized['effective_from'] = $this->input(
                'effective_from',
                $this->input('term_start_date')
            );
        }
        if ($this->hasAny(['effective_to', 'term_end_date'])) {
            $normalized['effective_to'] = $this->input(
                'effective_to',
                $this->input('term_end_date')
            );
        }
        if ($this->hasAny(['remarks', 'notes'])) {
            $normalized['remarks'] = $this->input('remarks', $this->input('notes'));
        }

        $this->merge($normalized);
    }
}
