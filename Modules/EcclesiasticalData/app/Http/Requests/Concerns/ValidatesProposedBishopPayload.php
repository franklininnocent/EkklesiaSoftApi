<?php

namespace Modules\EcclesiasticalData\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;

trait ValidatesProposedBishopPayload
{
    /**
     * @return array<string, mixed>
     */
    protected function proposedBishopDataRules(bool $fullNameRequired = true): array
    {
        $fullName = $fullNameRequired
            ? ['required', 'string', 'max:255']
            : ['sometimes', 'string', 'max:255'];

        return [
            'proposed_bishop_data.full_name' => $fullName,
            'proposed_bishop_data.given_name' => ['nullable', 'string', 'max:100'],
            'proposed_bishop_data.family_name' => ['nullable', 'string', 'max:100'],
            'proposed_bishop_data.religious_name' => ['nullable', 'string', 'max:100'],
            'proposed_bishop_data.date_of_birth' => ['nullable', 'date', 'before:today'],
            'proposed_bishop_data.ordained_priest_date' => ['nullable', 'date'],
            'proposed_bishop_data.ordained_bishop_date' => ['nullable', 'date'],
            'proposed_bishop_data.email' => ['nullable', 'email', 'max:255'],
            'proposed_bishop_data.phone' => ['nullable', 'string', 'max:50'],
            'proposed_bishop_data.education' => ['nullable', 'string', 'max:5000'],
            'proposed_bishop_data.biography' => ['nullable', 'string', 'max:10000'],
            'proposed_bishop_data.pending_photo_path' => ['prohibited'],
            'proposed_bishop_data.photo_path' => ['prohibited'],
            'proposed_bishop_data.photo_url' => ['prohibited'],
            'proposed_bishop_data.status' => ['prohibited'],
            'proposed_bishop_data.archdiocese_id' => ['prohibited'],
            'proposed_bishop_data.is_current' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function proposedAppointmentDataRules(): array
    {
        return [
            'proposed_appointment_data.effective_date' => ['nullable', 'date'],
            'proposed_appointment_data.appointed_date' => ['nullable', 'date'],
            'proposed_appointment_data.installed_date' => ['nullable', 'date'],
            'proposed_appointment_data.announced_date' => ['nullable', 'date'],
            'proposed_appointment_data.end_reason' => ['nullable', Rule::enum(AppointmentEndReason::class)],
        ];
    }
}
