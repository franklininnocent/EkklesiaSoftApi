<?php

namespace Modules\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['name', 'email', 'phone', 'organisation', 'subject', 'message', 'captcha_challenge_id', 'captcha_response'] as $field) {
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
        $messageMax = (int) config('marketing.web.enquiry.message_max', 5000);
        $nameMax = (int) config('marketing.web.enquiry.name_max', 120);

        return [
            'name' => ['required', 'string', 'max:'.$nameMax],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'regex:/^\+[0-9][0-9\s\-()]{7,18}$/'],
            'organisation' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:'.$messageMax],
            'captcha_challenge_id' => ['required', 'uuid'],
            'captcha_response' => ['required', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:0'],
            'correlation_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Include your country code, starting with +.',
            'website.max' => 'Unable to submit.',
            'captcha_challenge_id.required' => 'Please complete the security check.',
            'captcha_response.required' => 'Please enter the security check characters.',
        ];
    }
}
