<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class StoreMassIntentionRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return [
            'beneficiary_person_id' => ['nullable', 'uuid'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'],
            'beneficiary_place' => ['nullable', 'string', 'max:255'],
            'beneficiary_bcc_id' => ['prohibited'],
            'beneficiary_bcc_name' => ['prohibited'],
            'mass_intention_category_id' => [
                'required',
                'uuid',
                Rule::exists('mass_intention_categories', 'id')->where(function ($query) use ($tenantId) {
                    if ($tenantId !== null) {
                        $query->where('tenant_id', $tenantId);
                    }
                    $query->where('active', true)->whereNull('deleted_at');
                }),
            ],
            'intention_description' => ['nullable', 'string', 'max:2000'],
            'priest_text' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'announce_name' => ['nullable', 'boolean'],
            'requester_name' => ['nullable', 'string', 'max:255'],
            'requester_phone' => ['nullable', 'string', 'max:64'],
            'requested_date' => ['prohibited'],
            'celebration_id' => [
                'required',
                'uuid',
                Rule::exists('mass_celebrations', 'id')->where(function ($query) use ($tenantId) {
                    if ($tenantId !== null) {
                        $query->where('tenant_id', $tenantId);
                    }
                }),
            ],
            'date_must_be_kept' => ['nullable', 'boolean'],
            'prohibit_transfer' => ['nullable', 'boolean'],
            'is_collective' => ['nullable', 'boolean'],
            'mass_count' => ['nullable', 'integer', 'in:1'],
            'submit_for_review' => ['nullable', 'boolean'],
        ];
    }
}
