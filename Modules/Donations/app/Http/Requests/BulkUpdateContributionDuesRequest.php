<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateContributionDuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('due_ids', []);
        if (! is_array($ids)) {
            return;
        }

        $unique = [];
        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }
            $unique[$id] = $id;
        }

        $this->merge([
            'due_ids' => array_values($unique),
        ]);
    }

    public function rules(): array
    {
        return [
            'due_ids' => ['required', 'array', 'min:1', 'max:100'],
            'due_ids.*' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function dueIds(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated()['due_ids'];

        return $ids;
    }
}
