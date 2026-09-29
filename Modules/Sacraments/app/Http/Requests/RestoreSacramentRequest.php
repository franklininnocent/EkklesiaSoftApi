<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreSacramentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Optional: clients may send for consistency; restore does not bump version.
            'lock_version' => 'nullable|integer|min:0',
        ];
    }
}
