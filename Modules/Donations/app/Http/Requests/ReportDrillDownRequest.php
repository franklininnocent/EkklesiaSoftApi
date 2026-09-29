<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Donations\Contracts\ReportDrillDownAdapter;
use Modules\Donations\Services\ReportDrillDown\ReportDrillDownRegistry;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Tenants\Support\TenantContext;

class ReportDrillDownRequest extends FormRequest
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
        $registry = app(ReportDrillDownRegistry::class);
        $graphId = (string) $this->input('graph_id', '');
        $adapter = $registry->has($graphId) ? $registry->get($graphId) : null;

        $dataElements = $adapter?->supportedDataElementIds() ?? [];
        $sliceIds = $this->sliceIdsForAdapter($adapter);
        $dimensions = $adapter?->supportedDimensions() ?? ['family'];
        $sorts = $adapter?->supportedSorts() ?? ['outstanding_amount'];

        return array_merge(DashboardDateRangeRequest::dateRangeRules(), [
            'graph_id' => ['required', 'string', Rule::in($registry->registeredGraphIds())],
            'data_element_id' => ['required', 'string', Rule::in($dataElements)],
            'slice_id' => ['required', 'string', Rule::in($sliceIds)],
            'dimension' => ['sometimes', 'string', Rule::in($dimensions)],
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
            'filters' => ['sometimes', 'array'],
            'filters.bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.method' => ['sometimes', 'nullable', 'string', 'max:40'],
            'sort' => ['sometimes', 'string', Rule::in($sorts)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $graphId = (string) $this->input('graph_id', '');
            $registry = app(ReportDrillDownRegistry::class);
            if (! $registry->has($graphId)) {
                $validator->errors()->add('graph_id', 'Graph drill-down is not available yet.');

                return;
            }

            $adapter = $registry->get($graphId);
            $dataElementId = (string) $this->input('data_element_id', '');
            $sliceId = (string) $this->input('slice_id', '');

            $expectedSlice = $adapter->sliceIdForElement($dataElementId);
            if ($expectedSlice !== null && $expectedSlice !== $sliceId) {
                $validator->errors()->add('slice_id', 'Slice does not match the selected metric.');
            }

            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            try {
                $range = DashboardDateRange::tryFromInput($tenantId, [
                    'date_from' => $this->input('date_from'),
                    'date_to' => $this->input('date_to'),
                    'preset' => $this->input('preset'),
                ]);
            } catch (\InvalidArgumentException) {
                $range = null;
            }
            $allowed = $adapter->supportedSliceIdsForTenant($tenantId, $range);
            if ($allowed !== [] && ! in_array($sliceId, $allowed, true)) {
                $validator->errors()->add('slice_id', 'Slice is not valid for this graph.');
            }

            $filters = $this->input('filters', []);
            if (is_array($filters)) {
                $allowedFilters = $adapter->supportedFilterKeys();
                foreach (array_keys($filters) as $key) {
                    if (! in_array($key, $allowedFilters, true)) {
                        $validator->errors()->add('filters', 'Unknown filter key.');
                    }
                }
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function sliceIdsForAdapter(?ReportDrillDownAdapter $adapter): array
    {
        if ($adapter === null) {
            return [];
        }

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        try {
            $range = DashboardDateRange::tryFromInput($tenantId, [
                'date_from' => $this->input('date_from'),
                'date_to' => $this->input('date_to'),
                'preset' => $this->input('preset'),
            ]);
        } catch (\InvalidArgumentException) {
            $range = null;
        }
        $dynamic = $adapter->supportedSliceIdsForTenant($tenantId, $range);
        if ($dynamic !== []) {
            return $dynamic;
        }

        return $adapter->supportedSliceIds();
    }
}
