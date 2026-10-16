<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordRecoveryOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recovery_id' => ['required', 'uuid'],
            'otp' => ['required', 'string', 'size:6', 'regex:/^[A-Za-z0-9]{6}$/'],
        ];
    }
}
