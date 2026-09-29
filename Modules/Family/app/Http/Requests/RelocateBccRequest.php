<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class RelocateBccRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return [
            'target_bcc_id' => [
                'required',
                'uuid',
                Rule::exists('bccs', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'effective_date' => ['required', 'date', 'before_or_equal:today'],
            'historical_note' => ['nullable', 'string', 'max:2000'],
            'transition_id' => ['required', 'uuid'],
        ];
    }
}
