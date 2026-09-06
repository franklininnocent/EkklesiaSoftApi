<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class MarriageTransitionRequest extends FormRequest
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
            'outcome' => ['required', 'in:new_household,join_existing'],
            'effective_date' => ['required', 'date', 'before_or_equal:today'],
            'bride_member_id' => ['nullable', 'uuid', 'required_without:bride_external'],
            'groom_member_id' => ['nullable', 'uuid', 'required_without:groom_external'],
            'bride_external' => ['nullable', 'array', 'required_without:bride_member_id'],
            'groom_external' => ['nullable', 'array', 'required_without:groom_member_id'],
            'new_household' => ['required_if:outcome,new_household', 'array'],
            'new_household.family_name' => ['required_if:outcome,new_household', 'string', 'max:255'],
            'new_household.bcc_id' => ['required_if:outcome,new_household', 'uuid'],
            'new_household_head_member_id' => ['required_if:outcome,new_household', 'string'],
            'target_family_id' => [
                'required_if:outcome,join_existing',
                'uuid',
                Rule::exists('families', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'joining_member_id' => ['required_if:outcome,join_existing', 'uuid'],
            'partner_member_id' => ['nullable', 'uuid'],
            'external_spouse' => ['nullable', 'array'],
            'origin_successions' => ['nullable', 'array'],
            'origin_successions.*.origin_family_id' => ['required_with:origin_successions', 'uuid'],
            'origin_successions.*.replacement_head_member_id' => ['nullable', 'uuid'],
        ];
    }
}
