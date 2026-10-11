<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebSettingsRequest extends FormRequest
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
            'contact_recipient_email' => ['required', 'email:rfc', 'max:255'],
            'contact_form_enabled' => ['required', 'boolean'],
            'captcha_enabled' => ['required', 'boolean'],
            'captcha_ttl_seconds' => ['required', 'integer', 'min:60', 'max:900'],
            'enquiry_retention_days' => ['required', 'integer', 'min:30', 'max:3650'],
        ];
    }
}
