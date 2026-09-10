<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\EcclesiasticalData\Http\Requests\Concerns\ValidatesProposedBishopPayload;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;

class UpdateChurchBishopUpdateRequest extends FormRequest
{
    use ValidatesProposedBishopPayload;

    public function authorize(): bool
    {
        $request = BishopUpdateRequest::query()->find($this->route('id'));

        return $request && ($this->user()?->can('update', $request) ?? false);
    }

    public function rules(): array
    {
        return array_merge([
            'version' => ['required', 'integer', 'min:1'],
            'target_bishop_id' => ['sometimes', 'nullable', 'exists:bishops,id'],
            'proposed_bishop_data' => ['sometimes', 'array'],
            'proposed_appointment_data' => ['sometimes', 'nullable', 'array'],
            'supporting_information' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'submission_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], $this->proposedBishopDataRules(false), $this->proposedAppointmentDataRules());
    }
}
