<?php

namespace Modules\EcclesiasticalData\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;

class SubmitChurchBishopUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = BishopUpdateRequest::query()->find($this->route('id'));

        return $request && ($this->user()?->can('submit', $request) ?? false);
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
