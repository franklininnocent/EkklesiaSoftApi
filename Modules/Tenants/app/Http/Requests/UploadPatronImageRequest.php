<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadPatronImageRequest extends FormRequest
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
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ];
    }
}
