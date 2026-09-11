<?php

namespace Modules\ApplicationAccess\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Authentication\Models\User;

class StoreApplicationIpBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ip_address' => ['required', 'string', 'max:45'],
            'cidr' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }
}
