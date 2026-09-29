<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BCC\Http\Requests\Concerns\PaginatesBcc;

class IndexBccMembersRequest extends FormRequest
{
    use PaginatesBcc;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive,migrated'],
            'is_current' => ['sometimes', 'nullable'],
        ]);
    }
}
