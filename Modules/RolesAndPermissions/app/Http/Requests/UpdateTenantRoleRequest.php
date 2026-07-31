<?php

namespace Modules\RolesAndPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $roleId = $this->route('id');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($roleId),
            ],
            'description' => ['nullable', 'string'],
            'level' => ['sometimes', 'required', 'integer', 'min:1', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
