<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Tenants\Support\TenantContext;

class IndexProjectFamilyProgressRequest extends FormRequest
{
    public const STATUSES = [
        'completed',
        'partial',
        'not_started',
    ];

    public const SORT_COLUMNS = [
        'family_name',
        'family_code',
        'target_amount',
        'amount_collected',
        'outstanding_amount',
        'completion_percentage',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('tenant_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(self::STATUSES)],
            'bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('bcc_id')) {
                return;
            }

            try {
                $this->bccFilter(app(TenantContext::class)->requireEffectiveTenantId());
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('bcc_id', $exception->getMessage());
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

        return $sort !== '' ? $sort : 'outstanding_amount';
    }

    public function sortDirection(): string
    {
        if ($this->filled('direction')) {
            return $this->input('direction') === 'desc' ? 'desc' : 'asc';
        }

        return in_array($this->sortColumn(), ['family_name', 'family_code'], true) ? 'asc' : 'desc';
    }

    /**
     * @return array{search: string, status: string|null, sort: string, direction: string, page: int, per_page: int}
     */
    public function listOptions(): array
    {
        return [
            'search' => trim($this->string('search')->toString()),
            'status' => $this->filled('status') ? $this->string('status')->toString() : null,
            'sort' => $this->sortColumn(),
            'direction' => $this->sortDirection(),
            'page' => (int) $this->input('page', 1),
            'per_page' => (int) $this->input('per_page', 20),
        ];
    }
}
