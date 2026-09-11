<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ApplicationAccess\Http\Requests\Concerns\InteractsWithApplicationAccessPagination;
use Modules\ApplicationAccess\Support\ApplicationAccessFilterCatalog;
use Modules\Authentication\Models\User;

class ListApplicationSecuritySignalsRequest extends FormRequest
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
            'signal_type' => ['sometimes', 'string', Rule::in(ApplicationAccessFilterCatalog::SIGNAL_TYPES)],
            'source_ip' => ['sometimes', 'string', 'max:45'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
    }
}
