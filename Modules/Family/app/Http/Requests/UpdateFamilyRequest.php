<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\PhoneNumberForTenant;
use Modules\Tenants\Support\TenantContext;

class UpdateFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_name' => ['sometimes', 'string', 'max:255'],
            'head_of_family' => ['nullable', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'bcc_id' => [
                'nullable',
                'uuid',
                Rule::exists('bccs', 'id')->where(function ($query) {
                    $tenantId = app(TenantContext::class)->effectiveTenantId();
                    $query->where('tenant_id', $tenantId)->whereNull('deleted_at');
                }),
            ],
            'status' => ['nullable', 'in:active,inactive,migrated'],
            'notes' => ['nullable', 'string'],

            // members (optional)
            'members' => ['nullable', 'array'],
            'members.*.first_name' => ['required_with:members', 'string', 'max:120'],
            'members.*.last_name' => ['required_with:members', 'string', 'max:120'],
            'members.*.phone' => ['nullable', new PhoneNumberForTenant()],
            'members.*.email' => ['nullable', 'email', 'max:255'],
        ];
    }
}


