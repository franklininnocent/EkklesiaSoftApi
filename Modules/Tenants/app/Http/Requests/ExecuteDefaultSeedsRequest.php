<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;

class ExecuteDefaultSeedsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var DefaultSeedRegistry $registry */
        $registry = app(DefaultSeedRegistry::class);

        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string', Rule::in($registry->ids())],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Select at least one recommended list to add.',
            'ids.*.in' => 'One or more selected lists are not available.',
        ];
    }
}
