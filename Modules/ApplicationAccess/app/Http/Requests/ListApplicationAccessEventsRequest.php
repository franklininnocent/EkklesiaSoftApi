<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ApplicationAccess\Http\Requests\Concerns\InteractsWithApplicationAccessPagination;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;
use Modules\Authentication\Models\User;

class ListApplicationAccessEventsRequest extends FormRequest
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
        return array_merge($this->paginationRules(), [
            'event_type' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::EVENT_TYPES)],
            'action' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::ACTIONS)],
            'authorization_result' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::AUTHORIZATION_RESULTS)],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'access_session_id' => ['sometimes', 'uuid'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'module_code' => ['sometimes', 'string', 'max:64'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }
}
