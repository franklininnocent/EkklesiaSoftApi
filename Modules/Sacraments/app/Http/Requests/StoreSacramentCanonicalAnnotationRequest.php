<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Sacraments\Support\SacramentAnnotationType;

class StoreSacramentCanonicalAnnotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'annotation_type' => ['required', SacramentAnnotationType::rule()],
            'effective_date' => 'nullable|date',
            'granting_authority' => 'nullable|string|max:255',
            'protocol_number' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:4000',
        ];
    }
}
