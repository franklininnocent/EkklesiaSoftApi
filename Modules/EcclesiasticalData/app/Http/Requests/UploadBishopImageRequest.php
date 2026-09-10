<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\EcclesiasticalData\Models\BishopManagement;

class UploadBishopImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $bishop = BishopManagement::query()->find($this->route('id'));

        return $bishop && ($this->user()?->can('manageImages', $bishop) ?? false);
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ];
    }
}
