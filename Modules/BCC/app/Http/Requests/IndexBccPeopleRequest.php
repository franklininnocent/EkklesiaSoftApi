<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Http\Requests\Concerns\PaginatesBcc;
use Modules\BCC\Support\BccAgeBands;

class IndexBccPeopleRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', 'in:active,inactive,deceased,migrated'],
            'gender' => ['sometimes', 'nullable', 'in:male,female,other,unknown'],
            'age_band' => ['sometimes', 'nullable', Rule::in(BccAgeBands::keys())],
        ]);
    }
}
