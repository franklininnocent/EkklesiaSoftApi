<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class EnrollFamilyMemberRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'uuid', $this->tenantExists('ma_organizations')],
            'member_type' => ['sometimes', 'string', Rule::in(self::MEMBER_TYPES)],
            'joined_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
