<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\MinistriesAssociations\Http\Requests\Concerns\InteractsWithMinistriesValidation;

class ParishionerLookupRequest extends FormRequest
{
    use InteractsWithMinistriesValidation;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->paginationRules(50), [
            'search' => ['sometimes', 'string', 'min:2', 'max:255'],
            'exclude_organization_id' => ['sometimes', 'uuid', $this->tenantExists('ma_organizations')],
        ]);
    }
}
