<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class IndexOrganizationMembershipRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in([
                OrganizationMembership::STATUS_ACTIVE,
                OrganizationMembership::STATUS_INACTIVE,
                OrganizationMembership::STATUS_SUSPENDED,
                OrganizationMembership::STATUS_RESIGNED,
                OrganizationMembership::STATUS_EXITED,
                OrganizationMembership::STATUS_DECEASED,
            ])],
            'member_type' => ['sometimes', 'string', Rule::in(self::MEMBER_TYPES)],
            'member_source' => ['sometimes', 'string', Rule::in([
                OrganizationMembership::SOURCE_PARISH,
                OrganizationMembership::SOURCE_GUEST,
            ])],
            'is_current' => ['sometimes', 'boolean'],
        ]);
    }
}
