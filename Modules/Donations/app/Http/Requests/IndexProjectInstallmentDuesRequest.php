<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Family\Models\Family;
use Modules\Tenants\Support\TenantContext;

class IndexProjectInstallmentDuesRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'family_name',
        'project_name',
        'installment_label',
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
        $this->offsetUnset('tenant_id');

        if (! $this->has('overdue_only')) {
            return;
        }
        $value = $this->input('overdue_only');
        if (! is_string($value)) {
            return;
        }
        $normalized = strtolower($value);
        if ($normalized === 'true' || $normalized === 'false') {
            $this->merge(['overdue_only' => filter_var($value, FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'family_id' => ['sometimes', 'nullable', 'uuid'],
            'bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['pending', 'partially_paid', 'paid', 'waived', 'cancelled'])],
            'overdue_only' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'due_date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:due_date_from'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

            try {
                $bccFilter = $this->bccFilter($tenantId);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('bcc_id', $exception->getMessage());

                return;
            }

            if (! $this->filled('family_id')) {
                return;
            }

            $family = Family::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->string('family_id'))
                ->first();

            if ($family === null) {
                $validator->errors()->add('family_id', 'The selected family is not available for this parish.');

                return;
            }

            if (! $bccFilter->familyBelongsToFilter($family)) {
                $validator->errors()->add('family_id', 'The selected family does not belong to the chosen BCC.');
            }
        });
    }

    public function bccFilter(int $tenantId): DashboardBccFilter
    {
        if (! $this->filled('bcc_id')) {
            return DashboardBccFilter::none();
        }

        return DashboardBccFilter::resolve($tenantId, $this->input('bcc_id'));
    }

    public function sortColumn(): string
    {
        $sort = $this->string('sort')->toString();

        return $sort !== '' ? $sort : 'outstanding';
    }

    public function sortDirection(): string
    {
        if ($this->filled('direction')) {
            return $this->input('direction') === 'desc' ? 'desc' : 'asc';
        }

        return $this->sortColumn() === 'outstanding' ? 'desc' : 'asc';
    }
}
