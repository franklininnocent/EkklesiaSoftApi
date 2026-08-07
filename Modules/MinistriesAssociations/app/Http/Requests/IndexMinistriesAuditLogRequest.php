<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class IndexMinistriesAuditLogRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'entity_type' => ['sometimes', 'string', 'max:60'],
            'entity_id' => ['sometimes', 'uuid'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'action_type' => ['sometimes', 'string', 'max:120'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ]);
    }
}
