<?php

namespace Modules\RolesAndPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncTenantRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['required', 'integer', 'exists:permissions,id'],
        ];
    }
}
