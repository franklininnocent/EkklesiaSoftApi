<?php

namespace Modules\MinistriesAssociations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaxonomyStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
