<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;

class EndAppointmentRequest extends FormRequest
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
            'ended_date' => ['required', 'date'],
            'end_reason' => ['required', Rule::enum(AppointmentEndReason::class)],
        ];
    }
}
