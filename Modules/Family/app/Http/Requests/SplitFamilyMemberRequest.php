<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'uuid'],
            'new_family' => ['required', 'array'],
            'new_family.family_name' => ['required', 'string', 'max:255'],
            'new_family.address_line_1' => ['nullable', 'string', 'max:255'],
            'new_family.city' => ['nullable', 'string', 'max:120'],
            'new_family.postal_code' => ['nullable', 'string', 'max:40'],
            'new_family.status' => ['nullable', 'in:active,inactive,migrated'],
            'member_overrides' => ['nullable', 'array'],
            'member_overrides.relationship_to_head' => ['nullable', 'in:self,head,spouse,son,daughter,father,mother,brother,sister,grandfather,grandmother,grandson,granddaughter,uncle,aunt,nephew,niece,cousin,other'],
        ];
    }
}
