<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantSubscriptionAccessPolicyRequest extends FormRequest
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
            'custom_grace_period_days' => 'nullable|integer|min:0|max:365',
            'custom_view_only_allowance_days' => 'nullable|integer|min:0|max:365',
        ];
    }
}
