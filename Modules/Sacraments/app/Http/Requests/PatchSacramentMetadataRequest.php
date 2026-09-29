<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Tenants\Support\TenantContext;

class PatchSacramentMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $sacramentId = (int) $this->route('id');

        return [
            'lock_version' => 'required|integer|min:0',
            'notes' => 'nullable|string',
            'book_number' => 'nullable|string|max:255',
            'page_number' => 'nullable|string|max:255',
            'registry_entry' => 'nullable|string|max:100',
            'certificate_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('sacraments', 'certificate_number')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($sacramentId),
            ],
            'place_administered' => 'nullable|string|max:255',
        ];
    }
}
