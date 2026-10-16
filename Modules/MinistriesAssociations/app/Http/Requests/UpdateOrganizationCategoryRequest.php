<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class UpdateOrganizationCategoryRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = (string) $this->route('categoryId');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z0-9-]+$/',
                $this->tenantUnique('ma_organization_categories', 'code', $categoryId),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:100',
                $this->tenantUnique('ma_organization_categories', 'name', $categoryId),
            ],
            'description' => ['sometimes', 'nullable', 'string'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
