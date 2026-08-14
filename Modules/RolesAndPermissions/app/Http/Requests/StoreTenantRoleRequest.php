<?php

namespace Modules\RolesAndPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => trim($this->input('name')),
            ]);
        }
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string'],
            'level' => ['required', 'integer', 'min:1', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
