<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;
use Modules\MinistriesAssociations\Models\GuestMember;

class StoreGuestMemberRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'guest_type' => ['sometimes', 'string', Rule::in([
                GuestMember::GUEST_TYPE_SUPPORTER,
                GuestMember::GUEST_TYPE_VOLUNTEER,
                GuestMember::GUEST_TYPE_BENEFACTOR,
                GuestMember::GUEST_TYPE_ADVISOR,
                GuestMember::GUEST_TYPE_RESOURCE_PERSON,
            ])],
            'external_organization' => ['nullable', 'string', 'max:255'],
            'support_type' => ['nullable', 'string', Rule::in(['financial', 'labor', 'advisory'])],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
