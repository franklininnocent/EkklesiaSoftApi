<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\BCC\Models\BCC;
use Modules\Tenants\Support\TenantContext;

class MemberCelebrationsListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['birthdays', 'anniversaries'])],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:255'],
            'bcc_id' => ['nullable', 'string', 'max:64'],
            'event_date' => ['nullable', 'date_format:Y-m-d'],
            'event_date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:event_date_to'],
            'event_date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:event_date_from', 'after_or_equal:event_date_from'],
            'sort_by' => ['nullable', Rule::in(['event_date', 'name', 'family_name', 'bcc_name'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bccId = $this->input('bcc_id');
            if ($bccId === null || $bccId === '') {
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
