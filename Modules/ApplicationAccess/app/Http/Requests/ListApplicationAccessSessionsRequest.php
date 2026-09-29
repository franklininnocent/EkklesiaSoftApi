<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ApplicationAccess\Http\Requests\Concerns\InteractsWithApplicationAccessPagination;
use Modules\ApplicationAccess\Support\ApplicationAccessAuthorization;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;
use Modules\Authentication\Models\User;

class ListApplicationAccessSessionsRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::SESSION_STATUSES)],
            'identity_type' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::IDENTITY_TYPES)],
            'access_context' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::ACCESS_CONTEXTS)],
            'tenant_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'ip_address' => ['sometimes', 'string', 'max:45'],
            'country' => ['sometimes', 'string', 'size:2'],
            'started_from' => ['sometimes', 'date'],
            'started_to' => ['sometimes', 'date'],
            'q' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'string', 'max:255'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $user = $this->user();
        $canSearchEmail = $user instanceof User
            && ApplicationAccessAuthorization::hasAny($user, ['application_access.investigate']);

        if (! $canSearchEmail) {
            unset($validated['email']);
        }

        $validated['can_search_email'] = $canSearchEmail;

        return $validated;
    }
}
