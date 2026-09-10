<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\EcclesiasticalData\Http\Requests\Concerns\ValidatesProposedBishopPayload;
use Modules\EcclesiasticalData\Support\BishopUpdateRequestType;

class StoreChurchBishopUpdateRequest extends FormRequest
{
    use ValidatesProposedBishopPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('create', \Modules\EcclesiasticalData\Models\BishopUpdateRequest::class) ?? false;
    }

    public function rules(): array
    {
        return array_merge([
            'request_type' => ['required', Rule::enum(BishopUpdateRequestType::class)],
            'target_bishop_id' => ['nullable', 'exists:bishops,id'],
            'proposed_bishop_data' => ['required', 'array'],
            'proposed_appointment_data' => ['nullable', 'array'],
            'supporting_information' => ['nullable', 'string', 'max:5000'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'submission_notes' => ['nullable', 'string', 'max:2000'],
        ], $this->proposedBishopDataRules(true), $this->proposedAppointmentDataRules());
    }
}
