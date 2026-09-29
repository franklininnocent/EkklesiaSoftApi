<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BCC\Http\Requests\Concerns\PaginatesBcc;

class LookupBccFamiliesRequest extends FormRequest
{
    use PaginatesBcc;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(50), [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'exclude_bcc_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }
}
