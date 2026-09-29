<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Policies\Concerns\AuthorizesEcclesiasticalPermission;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class ReplaceOrdinaryRequest extends FormRequest
{
    use AuthorizesEcclesiasticalPermission;

    public function authorize(): bool
    {
        return $this->allowsPlatform($this->user(), 'bishops.manage_appointments');
    }

    public function rules(): array
    {
        return [
            'bishop_id' => ['required_without:person', 'nullable', 'exists:bishops,id'],
            'person' => ['required_without:bishop_id', 'nullable', 'array'],
            'person.full_name' => ['required_with:person', 'string', 'max:255'],
            'person.given_name' => ['nullable', 'string', 'max:100'],
            'person.family_name' => ['nullable', 'string', 'max:100'],
            'person.religious_name' => ['nullable', 'string', 'max:100'],
            'person.date_of_birth' => ['nullable', 'date', 'before:today'],
            'person.email' => ['nullable', 'email', 'max:255'],
            'person.phone' => ['nullable', 'string', 'max:50'],
            'appointment' => ['required', 'array'],
            'appointment.canonical_role' => ['nullable', Rule::enum(CanonicalRole::class)],
            'appointment.effective_date' => ['required', 'date'],
            'appointment.appointed_date' => ['nullable', 'date'],
            'appointment.announced_date' => ['nullable', 'date'],
            'appointment.installed_date' => ['nullable', 'date'],
            'appointment.ecclesiastical_title_id' => ['nullable', 'exists:ecclesiastical_titles,id'],
            'end_reason' => ['nullable', Rule::enum(AppointmentEndReason::class)],
        ];
    }
}
