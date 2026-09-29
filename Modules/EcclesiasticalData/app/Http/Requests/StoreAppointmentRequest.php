<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\AppointmentStatus;
use Modules\EcclesiasticalData\Support\CanonicalRole;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bishop = BishopManagement::query()->find($this->route('bishopId') ?? $this->route('id'));

        return $bishop && ($this->user()?->can('manageAppointments', $bishop) ?? false);
    }

    public function rules(): array
    {
        return [
            'diocese_id' => ['required', 'exists:archdioceses,id'],
            'ecclesiastical_title_id' => ['nullable', 'exists:ecclesiastical_titles,id'],
            'canonical_role' => ['required', Rule::enum(CanonicalRole::class)],
            'appointed_date' => ['nullable', 'date'],
            'announced_date' => ['nullable', 'date'],
            'effective_date' => ['required', 'date'],
            'ordained_date' => ['nullable', 'date'],
            'installed_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'is_current' => ['sometimes', 'boolean'],
            'appointment_status' => ['nullable', Rule::enum(AppointmentStatus::class)],
            'appointment_details' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'source_type' => ['nullable', 'string', 'max:50'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
