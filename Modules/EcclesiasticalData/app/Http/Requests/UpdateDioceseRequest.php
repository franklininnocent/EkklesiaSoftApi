<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDioceseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('diocese'));
    }

    public function rules(): array
    {
        $dioceseId = $this->route('diocese');
        
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('archdioceses')->ignore($dioceseId)],
            'denomination_id' => ['sometimes', 'required', 'exists:denominations,id'],
            'country_id' => ['sometimes', 'required', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'is_archdiocese' => ['boolean'],
            'website' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'founded_year' => ['nullable', 'integer', 'min:1', 'max:' . date('Y')],
            'patron_saint' => ['nullable', 'string', 'max:255'],
            'feast_day' => ['nullable', 'date_format:Y-m-d'],
            'population' => ['nullable', 'integer', 'min:0'],
            'parishes_count' => ['nullable', 'integer', 'min:0'],
            'priests_count' => ['nullable', 'integer', 'min:0'],
            'religious_count' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}

