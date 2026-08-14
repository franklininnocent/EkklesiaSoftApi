<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\BCC\Http\Requests\Concerns\PaginatesBcc;

class IndexBccAuditLogRequest extends FormRequest
{
    use PaginatesBcc;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'event' => ['sometimes', 'nullable', 'string', 'max:120'],
            'target_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }
}
