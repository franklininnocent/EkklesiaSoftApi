<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadSelfProfileImageRequest extends FormRequest
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
            'profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ];
    }
}
