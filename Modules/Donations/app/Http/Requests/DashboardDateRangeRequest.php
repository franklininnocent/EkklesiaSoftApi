<?php

namespace Modules\Donations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Donations\Support\DashboardBccFilter;
use Modules\Donations\Support\DashboardDateRange;
use Modules\Donations\Support\DashboardProjectFilter;
use Modules\Tenants\Support\TenantContext;

class DashboardDateRangeRequest extends FormRequest
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
        return self::dateRangeRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function dateRangeRules(): array
    {
        return [
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_with:date_from'],
            'preset' => ['sometimes', 'nullable', 'string', Rule::in([
                DashboardDateRange::PRESET_THIS_MONTH,
                DashboardDateRange::PRESET_THIS_FY,
                DashboardDateRange::PRESET_LAST_90,
                DashboardDateRange::PRESET_YTD,
                DashboardDateRange::PRESET_CUSTOM,
            ])],
            'bcc_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $preset = $this->input('preset');
            $from = $this->input('date_from');
            $to = $this->input('date_to');
            if (($preset === null || $preset === '') && ($from === null || $from === '') && ($to === null || $to === '')) {
                return;
            }

            if ($preset === DashboardDateRange::PRESET_CUSTOM && ($from === null || $from === '' || $to === null || $to === '')) {
                $validator->errors()->add('date_from', 'Choose a start and end date.');

                return;
            }

            try {
                $this->resolvedRange();
                $this->resolvedBccFilter();
                $this->resolvedProjectFilter();
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('date_from', $exception->getMessage());
            }
        });
    }

    public function resolvedBccFilter(): DashboardBccFilter
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return DashboardBccFilter::resolve($tenantId, $this->input('bcc_id'));
    }

    public function resolvedProjectFilter(): DashboardProjectFilter
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return DashboardProjectFilter::resolve($tenantId, $this->input('project_id'));
    }

    public function resolvedRange(): ?DashboardDateRange
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return DashboardDateRange::tryFromInput($tenantId, [
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'preset' => $this->input('preset'),
        ]);
    }
}
