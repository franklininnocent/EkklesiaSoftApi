<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class CorrectHouseholdHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return [
            'transition_id' => ['required', 'uuid'],
            'corrects_history_id' => [
                'required',
                'uuid',
                Rule::exists('family_member_histories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'member_id' => [
                'required',
                'uuid',
                Rule::exists('family_members', 'id'),
            ],
            'from_family_id' => ['nullable', 'uuid'],
            'to_family_id' => ['nullable', 'uuid'],
            'previous_family_role' => ['nullable', 'string', 'max:40'],
            'new_family_role' => ['nullable', 'string', 'max:40'],
            'effective_date' => ['required', 'date', 'before_or_equal:today'],
            'metadata' => ['nullable', 'array'],
            'correction_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
