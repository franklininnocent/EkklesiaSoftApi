<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ApplicationAccess\Http\Requests\Concerns\InteractsWithApplicationAccessPagination;
use Modules\Authentication\Models\User;

class SessionTimelineRequest extends FormRequest
{
    use InteractsWithApplicationAccessPagination;

    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(50), [
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);
    }
}
