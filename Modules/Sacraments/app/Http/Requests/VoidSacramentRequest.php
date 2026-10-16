<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidSacramentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => 'required|integer|min:0',
            'reason' => 'required|string|min:3|max:2000',
        ];
    }
}
