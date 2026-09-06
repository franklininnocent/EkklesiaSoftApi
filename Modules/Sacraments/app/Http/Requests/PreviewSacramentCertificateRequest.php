<?php

namespace Modules\Sacraments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewSacramentCertificateRequest extends FormRequest
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
            'language' => 'nullable|string|max:16',
            'locale' => 'nullable|string|max:32',
            'paper' => 'nullable|in:A4,LETTER,a4,letter',
        ];
    }
}
