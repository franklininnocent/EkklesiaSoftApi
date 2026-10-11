<?php

namespace Modules\Subscriptions\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewTenantPlanChangeRequest extends FormRequest
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
            'plan_code' => [
                'required',
                'string',
                'max:64',
                Rule::exists('subscription_plans', 'code')->where(
                    fn ($q) => $q->where('is_public', true)->where('is_assignable', true)->where('is_legacy', false)
                ),
            ],
        ];
    }
}
