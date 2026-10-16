<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionSettingsRequest extends FormRequest
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
            'grace_period_days' => 'required|integer|min:0|max:365',
            'expiring_warning_days' => 'required|integer|min:0|max:365',
            'view_only_allowance_days' => 'required|integer|min:0|max:365',
        ];
    }
}
