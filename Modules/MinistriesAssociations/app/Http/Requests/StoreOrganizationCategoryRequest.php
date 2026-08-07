<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class StoreOrganizationCategoryRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z0-9-]+$/',
                $this->tenantUnique('ma_organization_categories', 'code'),
            ],
            'name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                $this->tenantUnique('ma_organization_categories', 'name'),
            ],
            'description' => ['nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
