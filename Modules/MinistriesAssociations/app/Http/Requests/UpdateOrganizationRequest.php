<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\Organization;

class UpdateOrganizationRequest extends FormRequest
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
        $organizationId = (string) $this->route('organizationId');

        return array_merge([
            'code' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z0-9-]+$/',
                $this->tenantUnique('ma_organizations', 'code', $organizationId),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:255',
                $this->tenantUnique('ma_organizations', 'name', $organizationId),
            ],
            'category_id' => ['sometimes', 'required', 'uuid', $this->tenantExists('ma_organization_categories')],
            'type_id' => ['sometimes', 'required', 'uuid', $this->tenantExists('ma_organization_types')],
            'short_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'vision' => ['sometimes', 'nullable', 'string'],
            'mission' => ['sometimes', 'nullable', 'string'],
            'objectives' => ['sometimes', 'nullable', 'string'],
            'patron_saint' => ['sometimes', 'nullable', 'string', 'max:150'],
            'established_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'theme_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', Rule::in([
                Organization::STATUS_ACTIVE,
                Organization::STATUS_INACTIVE,
            ])],
        ], $this->organizationSettingsRules(), $this->socialLinksRules());
    }
}
