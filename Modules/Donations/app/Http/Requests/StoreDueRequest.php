<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class StoreDueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'family_id' => [
                'required',
                'uuid',
                Rule::exists('families', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'plan_id' => [
                'required',
                'uuid',
                Rule::exists('contribution_plans', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'period_label' => ['required', 'string', 'max:50'],
            'due_date' => ['required', 'date'],
            'amount_due' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
