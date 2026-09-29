<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

class StoreOrganizationMembershipRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_source' => ['required', 'string', Rule::in([
                OrganizationMembership::SOURCE_PARISH,
                OrganizationMembership::SOURCE_GUEST,
            ])],
            'family_member_id' => [
                'required_if:member_source,'.OrganizationMembership::SOURCE_PARISH,
                'prohibited_if:member_source,'.OrganizationMembership::SOURCE_GUEST,
                'nullable',
                'uuid',
                $this->familyMemberExists(),
            ],
            'guest_member_id' => [
                'required_if:member_source,'.OrganizationMembership::SOURCE_GUEST,
                'prohibited_if:member_source,'.OrganizationMembership::SOURCE_PARISH,
                'nullable',
                'uuid',
                $this->tenantExists('ma_guest_members'),
            ],
            'member_type' => ['sometimes', 'string', Rule::in(self::MEMBER_TYPES)],
            'joined_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'emergency_contact' => ['nullable', 'string', 'max:100'],
        ];
    }
}
