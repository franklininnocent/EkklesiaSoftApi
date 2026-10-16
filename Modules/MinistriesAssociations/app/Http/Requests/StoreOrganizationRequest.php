<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\Organization;

class StoreOrganizationRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->flattenOrganizationSettings();
        $this->sanitizeRichTextFields([
            'description',
            'vision',
            'mission',
            'objectives',
        ]);
    }

    public function rules(): array
    {
        return array_merge([
            'code' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z0-9-]+$/',
                $this->tenantUnique('ma_organizations', 'code'),
            ],
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                $this->tenantUnique('ma_organizations', 'name'),
            ],
            'category_id' => ['required', 'uuid', $this->tenantExists('ma_organization_categories')],
            'type_id' => ['required', 'uuid', $this->tenantExists('ma_organization_types')],
            'short_name' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'vision' => ['nullable', 'string'],
            'mission' => ['nullable', 'string'],
            'objectives' => ['nullable', 'string'],
            'patron_saint' => ['nullable', 'string', 'max:150'],
            'established_date' => ['nullable', 'date', 'before_or_equal:today'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'website' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', Rule::in([
                Organization::STATUS_ACTIVE,
                Organization::STATUS_INACTIVE,
            ])],
        ], $this->organizationSettingsRules(), $this->socialLinksRules());
    }
}
