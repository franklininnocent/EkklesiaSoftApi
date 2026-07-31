<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fund_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:180'],
            'code' => ['required', 'string', 'max:60'],
            'campaign_type' => ['nullable', 'in:building,charity,event,general'],
            'description' => ['nullable', 'string'],
            'target_amount' => ['nullable', 'numeric', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['nullable', 'in:draft,active,completed,cancelled'],
            'is_tax_deductible' => ['nullable', 'boolean'],
        ];
    }
}
