<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveSacramentMigrationRequest extends FormRequest
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
        return [
            'resolution' => 'required|in:member,external',
            'family_member_id' => 'nullable|uuid|required_if:resolution,member',
            'external_full_name' => 'nullable|string|max:255|required_if:resolution,external',
            'external_date_of_birth' => 'nullable|date',
        ];
    }
}
