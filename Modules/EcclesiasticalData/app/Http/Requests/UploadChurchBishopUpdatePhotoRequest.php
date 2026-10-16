<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;

class UploadChurchBishopUpdatePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = BishopUpdateRequest::query()->find($this->route('id'));

        return $request && ($this->user()?->can('update', $request) ?? false);
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072'],
        ];
    }
}
