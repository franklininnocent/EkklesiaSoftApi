<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class ReEnrollOrganizationMembershipRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'joined_date' => ['required', 'date'],
            'member_type' => ['sometimes', 'string', Rule::in(self::MEMBER_TYPES)],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
