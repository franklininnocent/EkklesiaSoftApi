<?php

namespace Modules\MassIntentions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class BulkMoveMassIntentionRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->effectiveTenantId();

        return [
            'intention_ids' => ['required', 'array', 'min:1'],
            'intention_ids.*' => ['uuid'],
            'target_celebration_id' => [
                'required',
                'uuid',
                Rule::exists('mass_celebrations', 'id')->where(function ($query) use ($tenantId) {
                    if ($tenantId !== null) {
                        $query->where('tenant_id', $tenantId);
                    }
                }),
            ],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
