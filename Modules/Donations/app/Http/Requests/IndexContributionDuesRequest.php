<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexContributionDuesRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'family_name',
        'plan_name',
        'period_label',
        'due_date',
        'outstanding',
        'status',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['actionable', 'overdue_only', 'remaining_only'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $normalized = strtolower($value);
            if ($normalized === 'true' || $normalized === 'false') {
                $merge[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['pending', 'partially_paid', 'paid', 'waived', 'cancelled'])],
            'family_id' => ['sometimes', 'nullable', 'uuid'],
            'plan_id' => ['sometimes', 'nullable', 'uuid'],
            'due_schedule' => ['sometimes', 'nullable', 'string', Rule::in(['overdue', 'next_14_days', 'later'])],
            'overdue_only' => ['sometimes', 'boolean'],
            'remaining_only' => ['sometimes', 'boolean'],
            'actionable' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('due_schedule') && ($this->boolean('overdue_only') || $this->boolean('remaining_only'))) {
                $validator->errors()->add('due_schedule', 'Use either due_schedule or overdue_only/remaining_only, not both.');
            }
            if ($this->boolean('overdue_only') && $this->boolean('remaining_only')) {
                $validator->errors()->add('overdue_only', 'Use either overdue_only or remaining_only, not both.');
            }
        });
    }

    public function sortColumn(): string
    {
        $sort = $this->string('sort')->toString();

        return $sort !== '' ? $sort : 'due_date';
    }

    public function sortDirection(): string
    {
        return $this->input('direction', 'asc') === 'desc' ? 'desc' : 'asc';
    }
}
