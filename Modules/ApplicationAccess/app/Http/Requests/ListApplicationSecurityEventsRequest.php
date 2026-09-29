<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ApplicationAccess\Http\Requests\Concerns\InteractsWithApplicationAccessPagination;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;
use Modules\Authentication\Models\User;

class ListApplicationSecurityEventsRequest extends FormRequest
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
            'event_type' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::SECURITY_EVENT_TYPES)],
            'severity' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::SEVERITIES)],
            'actor_user_id' => ['sometimes', 'integer', 'min:1'],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'source_ip' => ['sometimes', 'string', 'max:45'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }
}
