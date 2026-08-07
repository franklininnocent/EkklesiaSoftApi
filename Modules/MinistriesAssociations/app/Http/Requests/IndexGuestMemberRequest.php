<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class IndexGuestMemberRequest extends FormRequest
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
            'guest_type' => ['sometimes', 'string', 'max:50'],
            'has_linked_parishioner' => ['sometimes', 'boolean'],
        ]);
    }
}
