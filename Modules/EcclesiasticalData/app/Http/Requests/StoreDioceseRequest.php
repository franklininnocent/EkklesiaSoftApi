<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDioceseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', 'Modules\EcclesiasticalData\Models\DioceseManagement');
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:archdioceses,code'],
            'denomination_id' => ['required', 'exists:denominations,id'],
            'country_id' => ['required', 'exists:countries,id'],
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

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Diocese name is required',
            'code.required' => 'Diocese code is required',
            'code.unique' => 'This diocese code is already in use',
            'denomination_id.required' => 'Denomination is required',
            'country_id.required' => 'Country is required',
            'website.url' => 'Please enter a valid website URL',
            'email.email' => 'Please enter a valid email address',
        ];
    }
}

