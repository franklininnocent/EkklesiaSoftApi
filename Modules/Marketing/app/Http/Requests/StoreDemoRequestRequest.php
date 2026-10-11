<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDemoRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'church_parish', 'diocese_organization', 'country', 'email', 'phone', 'role', 'message', 'captcha_challenge_id', 'captcha_response'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'church_parish' => ['required', 'string', 'max:200'],
            'diocese_organization' => ['nullable', 'string', 'max:200'],
            'country' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
            'captcha_challenge_id' => ['required', 'uuid'],
            'captcha_response' => ['required', 'string', 'max:32'],
            'company_website' => ['nullable', 'string', 'max:0'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'church_parish' => 'church or parish',
            'diocese_organization' => 'diocese or organization',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_website.max' => 'Unable to submit.',
            'captcha_challenge_id.required' => 'Please complete the security check.',
            'captcha_response.required' => 'Please enter the security check characters.',
        ];
    }
}
