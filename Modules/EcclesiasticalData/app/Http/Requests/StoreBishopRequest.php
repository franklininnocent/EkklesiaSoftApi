<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBishopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', 'Modules\EcclesiasticalData\Models\BishopManagement');
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'given_name' => ['nullable', 'string', 'max:100'],
            'family_name' => ['nullable', 'string', 'max:100'],
            'religious_name' => ['nullable', 'string', 'max:100'],
            'archdiocese_id' => ['required', 'exists:archdioceses,id'],
            'ecclesiastical_title_id' => ['nullable', 'exists:ecclesiastical_titles,id'],
            'appointed_date' => ['nullable', 'date'],
            'ordained_priest_date' => ['nullable', 'date'],
            'ordained_bishop_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'photo_url' => ['nullable', 'url'],
            'education' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,retired,deceased,inactive'],
            'is_current' => ['sometimes', 'boolean'],
        ];
    }
}

