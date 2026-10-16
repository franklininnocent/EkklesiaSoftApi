<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BCC\Http\Requests\Concerns\PaginatesBcc;
use Modules\BCC\Services\BccLeadershipService;

class IndexBccLeadershipRequest extends FormRequest
{
    use PaginatesBcc;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'role' => ['sometimes', 'nullable', Rule::in(BccLeadershipService::ROLES)],
            'is_active' => ['sometimes', 'nullable'],
        ]);
    }
}
