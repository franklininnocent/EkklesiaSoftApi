<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\LeadershipRoleCategory;
use Modules\Tenants\Support\LeadershipRoleNameNormalizer;

class StoreLeadershipRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'category' => ['sometimes', 'string', Rule::in(LeadershipRoleCategory::all())],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge([
                'title' => LeadershipRoleNameNormalizer::canonicalize((string) $this->input('title')),
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $title = (string) $this->input('title', '');
            if ($title === '') {
                $validator->errors()->add('title', 'Role title is required.');
            }
        });
    }
}
