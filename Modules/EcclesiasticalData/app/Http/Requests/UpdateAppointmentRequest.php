<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $appointment = BishopAppointment::query()->find($this->route('id'));

        if (! $appointment) {
            return false;
        }

        $bishop = BishopManagement::query()->find($appointment->bishop_id);

        return $bishop && ($this->user()?->can('manageAppointments', $bishop) ?? false);
    }

    public function rules(): array
    {
        return [
            'ecclesiastical_title_id' => ['sometimes', 'nullable', 'exists:ecclesiastical_titles,id'],
            'appointed_date' => ['sometimes', 'nullable', 'date'],
            'announced_date' => ['sometimes', 'nullable', 'date'],
            'effective_date' => ['sometimes', 'date'],
            'ordained_date' => ['sometimes', 'nullable', 'date'],
            'installed_date' => ['sometimes', 'nullable', 'date'],
            'appointment_details' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
