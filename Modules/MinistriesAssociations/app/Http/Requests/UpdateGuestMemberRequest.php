<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\GuestMember;

class UpdateGuestMemberRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'guest_type' => ['sometimes', 'string', Rule::in([
                GuestMember::GUEST_TYPE_SUPPORTER,
                GuestMember::GUEST_TYPE_VOLUNTEER,
                GuestMember::GUEST_TYPE_BENEFACTOR,
                GuestMember::GUEST_TYPE_ADVISOR,
                GuestMember::GUEST_TYPE_RESOURCE_PERSON,
            ])],
            'external_organization' => ['sometimes', 'nullable', 'string', 'max:255'],
            'support_type' => ['sometimes', 'nullable', 'string', Rule::in(['financial', 'labor', 'advisory'])],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
