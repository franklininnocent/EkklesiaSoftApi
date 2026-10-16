<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;

class ActivateSuccessionRequest extends FormRequest
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
            'end_reason' => ['nullable', Rule::enum(AppointmentEndReason::class)],
        ];
    }
}
