<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class GenerateContributionDuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'family_ids' => ['nullable', 'array', 'min:1'],
            'family_ids.*' => [
                'required',
                'uuid',
                Rule::exists('families', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'period_label' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'amount_due' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'use_current_period' => ['nullable', 'boolean'],
            'generate_full_schedule' => ['nullable', 'boolean'],
        ];
    }
}
