<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Export\TenantDataExportContributorRegistry;

class StoreTenantDataExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var TenantDataExportContributorRegistry $registry */
        $registry = app(TenantDataExportContributorRegistry::class);
        $keys = $registry->keys();

        return [
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['required', 'string', Rule::in($keys)],
            'include_media' => ['sometimes', 'boolean'],
            'format' => ['sometimes', 'string', Rule::in(['csv'])],
        ];
    }

    public function messages(): array
    {
        return [
            'modules.required' => 'Select at least one data module to export.',
            'modules.*.in' => 'One or more selected modules are not available for export.',
        ];
    }
}
