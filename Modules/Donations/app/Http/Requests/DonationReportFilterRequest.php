<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\Reports\DonationReportCatalog;
use Modules\Donations\Support\Reports\DonationReportExportFormat;
use Modules\Donations\Support\Reports\ReportDateSemantic;
use Modules\Donations\Support\Reports\ReportFilter;
use Modules\Tenants\Support\TenantContext;

class DonationReportFilterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $filters = $this->input('filters');
        if (is_array($filters)) {
            $this->merge($filters);
        }
    }

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
            'report_type' => ['required', 'string', Rule::in(DonationReportCatalog::allTypes())],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'as_of_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'fiscal_year' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preset' => ['sometimes', 'nullable', 'string', Rule::in([
                DashboardDateRange::PRESET_THIS_MONTH,
                DashboardDateRange::PRESET_THIS_FY,
                DashboardDateRange::PRESET_LAST_90,
                DashboardDateRange::PRESET_YTD,
                DashboardDateRange::PRESET_CUSTOM,
                'today',
            ])],
            'bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'method' => ['sometimes', 'nullable', 'string', 'max:40'],
            'plan_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'action_type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'entity_kind' => ['sometimes', 'nullable', 'string', Rule::in(['project', 'campaign'])],
            'participation_status' => ['sometimes', 'nullable', 'string', Rule::in(['participating', 'not_participating'])],
            'view' => ['sometimes', 'nullable', 'string', 'max:32'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:40'],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'today_only' => ['sometimes', 'boolean'],
            'export_format' => ['sometimes', 'nullable', 'string', Rule::in(DonationReportExportFormat::all())],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $this->resolvedFilter();
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('report_type', $exception->getMessage());
            }
        });
    }

    public function resolvedFilter(): ReportFilter
    {
        $reportType = (string) $this->input('report_type');
        $data = $this->validated();
        unset($data['report_type']);

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $definition = DonationReportCatalog::definition($reportType);
        if ($definition['date_semantic'] === ReportDateSemantic::AsOf && isset($data['as_of_date'])) {
            $parishToday = DonationBusinessDate::today($tenantId);
            if ((string) $data['as_of_date'] > $parishToday) {
                $data['as_of_date'] = $parishToday;
            }
        }

        $filter = ReportFilter::fromValidated($reportType, $data);
        DonationReportCatalog::assertSupportedFilters($reportType, $filter);

        if ($this->filled('bcc_id')) {
            DashboardBccFilter::resolve($tenantId, $this->input('bcc_id'));
        }
        if ($this->filled('project_id')) {
            DashboardProjectFilter::resolve($tenantId, $this->input('project_id'));
        }

        return $filter;
    }
}
