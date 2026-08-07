<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class LinkGuestMemberParishionerRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_member_id' => ['required', 'uuid', $this->familyMemberExists()],
        ];
    }
}
