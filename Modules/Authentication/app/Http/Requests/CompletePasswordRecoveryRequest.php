<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Authentication\Support\PasswordPolicy;

class CompletePasswordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recovery_id' => ['required', 'uuid'],
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => PasswordPolicy::validationRules(true),
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
