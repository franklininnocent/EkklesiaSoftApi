<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectPaymentContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'],
            'due_id' => ['nullable', 'uuid'],
            'installment_id' => ['nullable', 'uuid'],
        ];
    }
}
