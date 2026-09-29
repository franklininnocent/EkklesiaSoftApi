<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Tenants\Support\TenantContext;

class IndexDonationPaymentsRequest extends FormRequest
{
    public const STATUSES = [
        'pending',
        'succeeded',
        'failed',
        'reversed',
        'refunded',
    ];

    public const METHODS = [
        'cash',
        'bank_transfer',
        'cheque',
        'online_placeholder',
        'adjustment',
    ];

    public const SORT_COLUMNS = [
        'payment_number',
        'payment_date',
        'family_name',
        'family_code',
        'payer_name',
        'method',
        'status',
        'amount',
        'refunded_amount',
    ];

    public const DATE_BASIS = 'payment_date';

    public const MODE_TODAY = 'today';

    public const MODE_COLLECTION_DATE = 'collection_date';

    public const MODE_PAID_RANGE = 'paid_range';

    public const MODE_UNSCOPED = 'unscoped';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('tenant_id');

        $merge = [];
        if ($this->exists('payment_date_from') && ! $this->filled('paid_from')) {
            $merge['paid_from'] = $this->input('payment_date_from');
        }
        if ($this->exists('payment_date_to') && ! $this->filled('paid_to')) {
            $merge['paid_to'] = $this->input('payment_date_to');
        }
        if ($this->filled('sort_by') && ! $this->filled('sort')) {
            $merge['sort'] = $this->input('sort_by');
        }
        if ($this->filled('sort_dir') && ! $this->filled('direction')) {
            $merge['direction'] = $this->input('sort_dir');
        }
        if ($this->filled('sort_order') && ! $this->filled('direction') && ! isset($merge['direction'])) {
            $merge['direction'] = $this->input('sort_order');
        }
        if ($this->exists('per_page') && $this->input('per_page') !== null && $this->input('per_page') !== '') {
            $merge['per_page'] = (int) $this->input('per_page');
        }
        if ($this->exists('page') && $this->input('page') !== null && $this->input('page') !== '') {
            $merge['page'] = (int) $this->input('page');
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(self::STATUSES)],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(self::METHODS)],
            'family_id' => ['sometimes', 'nullable', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(self::SORT_COLUMNS)],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1'],
            'bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'today_only' => ['sometimes', 'nullable', 'boolean'],
            'collection_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'paid_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'paid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:paid_from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateAllowlists($validator);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $this->validateOwnedFilters($validator, $tenantId);
            $this->validateDateModes($validator, $tenantId);
        });
    }

    /**
     * @return array{mode: string, business_date: ?string, paid_from: ?string, paid_to: ?string}
     */
    public function resolvedDateWindow(int $tenantId): array
    {
        $parishToday = DonationBusinessDate::today($tenantId);
        $todayOnly = $this->boolean('today_only');
        $collectionDate = $this->filled('collection_date') ? (string) $this->input('collection_date') : null;
        $paidFrom = $this->filled('paid_from') ? (string) $this->input('paid_from') : null;
        $paidTo = $this->filled('paid_to') ? (string) $this->input('paid_to') : null;

        if ($todayOnly) {
            return [
                'mode' => self::MODE_TODAY,
                'business_date' => $parishToday,
                'paid_from' => $parishToday,
                'paid_to' => $parishToday,
            ];
        }

        if ($collectionDate !== null) {
            return [
                'mode' => self::MODE_COLLECTION_DATE,
                'business_date' => $collectionDate,
                'paid_from' => $collectionDate,
                'paid_to' => $collectionDate,
            ];
        }

        if ($paidFrom !== null || $paidTo !== null) {
            return [
                'mode' => self::MODE_PAID_RANGE,
                'business_date' => ($paidFrom !== null && $paidFrom === $paidTo) ? $paidFrom : null,
                'paid_from' => $paidFrom,
                'paid_to' => $paidTo,
            ];
        }

        return [
            'mode' => self::MODE_UNSCOPED,
            'business_date' => null,
            'paid_from' => null,
            'paid_to' => null,
        ];
    }

    public function toReportFilter(): ReportFilter
    {
        $data = $this->validated();
        unset($data['paid_from'], $data['paid_to'], $data['today_only'], $data['collection_date'], $data['page'], $data['per_page']);

        return ReportFilter::fromValidated('payments', $data);
    }

    private function validateOwnedFilters(Validator $validator, int $tenantId): void
    {
        if ($this->filled('bcc_id')) {
            try {
                DashboardBccFilter::resolve($tenantId, $this->input('bcc_id'));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('bcc_id', $exception->getMessage());
            }
        }

        if ($this->filled('project_id')) {
            try {
                DashboardProjectFilter::resolve($tenantId, $this->input('project_id'));
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('project_id', $exception->getMessage());
            }
        }
    }

    private function validateAllowlists(Validator $validator): void
    {
        $sort = $this->input('sort');
        if (is_string($sort) && $sort !== '' && ! in_array($sort, self::SORT_COLUMNS, true)) {
            $validator->errors()->add('sort', 'The selected sort column is invalid.');
        }

        $direction = strtolower((string) $this->input('direction', ''));
        if ($direction !== '' && ! in_array($direction, ['asc', 'desc'], true)) {
            $validator->errors()->add('direction', 'The selected sort direction is invalid.');
        }
    }

    private function validateDateModes(Validator $validator, int $tenantId): void
    {
        $parishToday = DonationBusinessDate::today($tenantId);
        $todayOnly = $this->boolean('today_only');
        $collectionDate = $this->filled('collection_date') ? (string) $this->input('collection_date') : null;
        $paidFrom = $this->filled('paid_from') ? (string) $this->input('paid_from') : null;
        $paidTo = $this->filled('paid_to') ? (string) $this->input('paid_to') : null;

        if ($todayOnly && $collectionDate !== null && $collectionDate !== $parishToday) {
            $validator->errors()->add('collection_date', 'collection_date cannot differ from the parish business date when today_only is set.');
        }

        if ($todayOnly && $paidFrom !== null && $paidFrom !== $parishToday) {
            $validator->errors()->add('paid_from', 'paid_from cannot differ from the parish business date when today_only is set.');
        }

        if ($todayOnly && $paidTo !== null && $paidTo !== $parishToday) {
            $validator->errors()->add('paid_to', 'paid_to cannot differ from the parish business date when today_only is set.');
        }

        if ($collectionDate !== null && $paidFrom !== null && $paidFrom !== $collectionDate) {
            $validator->errors()->add('paid_from', 'paid_from must match collection_date when both are provided.');
        }

        if ($collectionDate !== null && $paidTo !== null && $paidTo !== $collectionDate) {
            $validator->errors()->add('paid_to', 'paid_to must match collection_date when both are provided.');
        }
    }
}
