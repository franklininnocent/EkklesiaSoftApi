<?php

namespace Modules\Subscriptions\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBillingOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_code' => ['required', 'string', 'max:64'],
            'billing_interval' => ['required', Rule::in(['MONTHLY', 'ANNUAL'])],
        ];
    }

    public function idempotencyKey(): string
    {
        $header = $this->header('Idempotency-Key');
        if (is_string($header) && $header !== '') {
            return substr($header, 0, 64);
        }

        return substr(sha1($this->user()?->id.'|'.$this->input('plan_code').'|'.$this->input('billing_interval').'|'.now()->format('Y-m-d H:i')), 0, 64);
    }
}
