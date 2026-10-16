<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sacraments\Support\SacramentStatus;

class BulkUpdateSacramentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('status')) {
            $this->merge([
                'status' => SacramentStatus::normalize($this->input('status')) ?? $this->input('status'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:sacraments,id',
            'status' => ['required', SacramentStatus::validationRule()],
        ];
    }
}
