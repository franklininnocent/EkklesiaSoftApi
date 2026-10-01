<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\BCC\Models\BCC;
use Modules\Family\Support\FamilyQueryFilters;
use Modules\Tenants\Support\TenantContext;

class FamilyDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bcc_id' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'migrated'])],
            'period' => ['nullable', Rule::in(['30d', '3m', '6m', '12m', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:from'],
            'refresh' => ['nullable', 'boolean'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bccId = $this->input('bcc_id');
            if ($bccId === null || $bccId === '' || $bccId === FamilyQueryFilters::UNASSIGNED_BCC) {
                return;
            }

            $tenantId = app(TenantContext::class)->effectiveTenantId();
            $exists = BCC::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($bccId)
                ->exists();

            if (! $exists) {
                $validator->errors()->add('bcc_id', 'The selected BCC is not valid for this parish.');
            }
        });
    }
}
