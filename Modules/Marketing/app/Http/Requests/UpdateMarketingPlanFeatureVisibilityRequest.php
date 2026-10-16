<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMarketingPlanFeatureVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payload_hash' => ['required', 'string', 'size:64'],
            'hidden' => ['present', 'array'],
            'hidden.*' => ['array'],
            'hidden.*.*' => ['string', 'max:64'],
            'hidden_plans' => ['sometimes', 'array'],
            'hidden_plans.*' => ['string', 'max:64'],
        ];
    }
}
