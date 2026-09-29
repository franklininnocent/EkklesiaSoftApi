<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\Organization;

class IndexOrganizationRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('sort_dir') && $this->filled('sort_order')) {
            $this->merge(['sort_dir' => $this->input('sort_order')]);
        }
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'category_id' => ['sometimes', 'uuid', $this->tenantExists('ma_organization_categories')],
            'type_id' => ['sometimes', 'uuid', $this->tenantExists('ma_organization_types')],
            'status' => ['sometimes', 'string', Rule::in([
                Organization::STATUS_ACTIVE,
                Organization::STATUS_INACTIVE,
            ])],
            'sort_by' => ['sometimes', 'string', Rule::in([
                'name',
                'code',
                'established_date',
                'status',
                'created_at',
                'active_member_count',
            ])],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'include' => ['sometimes', 'string', 'max:255'],
        ]);
    }
}
