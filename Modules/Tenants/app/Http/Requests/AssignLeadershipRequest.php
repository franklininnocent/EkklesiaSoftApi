<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class AssignLeadershipRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return [
            'is_external' => ['required', 'boolean'],
            'person_id' => [
                'nullable',
                'required_if:is_external,false',
                'prohibited_if:is_external,true',
                'uuid',
                Rule::exists('persons', 'id')->where('tenant_id', $tenantId),
            ],
            'first_name' => [
                'nullable',
                'required_if:is_external,true',
                'prohibited_if:is_external,false',
                'string',
                'max:100',
            ],
            'last_name' => [
                'nullable',
                'required_if:is_external,true',
                'prohibited_if:is_external,false',
                'string',
                'max:100',
            ],
            'role_id' => ['required', 'uuid'],
            'appointment_date' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'jurisdiction_name' => ['nullable', 'string', 'max:255'],
            'appointment_letter_ref' => ['nullable', 'string', 'max:150'],
        ];
    }
}
