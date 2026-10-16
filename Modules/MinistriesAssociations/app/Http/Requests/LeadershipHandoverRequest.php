<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class LeadershipHandoverRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'uuid', $this->tenantExists('ma_positions')],
            'outgoing_term_id' => ['required', 'uuid', $this->tenantExists('ma_leadership_terms')],
            'outgoing' => ['required', 'array'],
            'outgoing.effective_to' => ['required', 'date'],
            'outgoing.exit_reason' => ['required', 'string', 'max:30', Rule::in(self::LEADERSHIP_HANDOVER_EXIT_REASONS)],
            'incoming' => ['required', 'array'],
            'incoming.membership_id' => ['required', 'uuid', $this->tenantExists('ma_memberships')],
            'incoming.appointment_date' => ['required', 'date', 'before_or_equal:incoming.effective_from'],
            'incoming.effective_from' => ['required', 'date'],
            'incoming.effective_to' => ['nullable', 'date', 'after_or_equal:incoming.effective_from'],
            'incoming.term_label' => ['nullable', 'string', 'max:50'],
            'incoming.appointment_reference' => ['nullable', 'string', 'max:100'],
            'incoming.is_interim' => ['sometimes', 'boolean'],
            'incoming.remarks' => ['nullable', 'string'],
        ];
    }
}
