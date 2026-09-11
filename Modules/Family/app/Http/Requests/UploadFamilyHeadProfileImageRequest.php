<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFamilyHeadProfileImageRequest extends FormRequest
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
            'head_profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }
}
