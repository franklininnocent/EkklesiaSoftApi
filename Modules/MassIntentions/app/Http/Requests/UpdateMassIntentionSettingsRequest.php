<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMassIntentionSettingsRequest extends FormRequest
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
            'suggested_offering_amount' => ['nullable', 'string', 'max:32'],
            'review_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'provincial_collective_authorized' => ['nullable', 'boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:64'],
        ];
    }
}
