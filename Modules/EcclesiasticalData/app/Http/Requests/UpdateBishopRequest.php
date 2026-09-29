<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

use Modules\EcclesiasticalData\Models\BishopManagement;

class UpdateBishopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bishop = BishopManagement::query()->find($this->route('id'));

        if (! $bishop) {
            return true;
        }

        return $this->user()?->can('update', $bishop) ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'given_name' => ['nullable', 'string', 'max:100'],
            'family_name' => ['nullable', 'string', 'max:100'],
            'religious_name' => ['nullable', 'string', 'max:100'],
            'archdiocese_id' => ['sometimes', 'exists:archdioceses,id'],
            'ecclesiastical_title_id' => ['nullable', 'exists:ecclesiastical_titles,id'],
            'appointed_date' => ['nullable', 'date'],
            'ordained_priest_date' => ['nullable', 'date'],
            'ordained_bishop_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'photo_url' => ['prohibited'],
            'education' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:active,retired,deceased,inactive'],
        ];
    }
}

