<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantSacramentSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => 'required|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'is_active' => 'status',
        ];
    }
}
