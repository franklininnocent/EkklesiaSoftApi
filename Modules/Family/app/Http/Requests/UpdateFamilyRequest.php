<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\PhoneNumberForTenant;

class UpdateFamilyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'family_name' => ['sometimes', 'string', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:40'],
            'bcc_id' => ['nullable', 'uuid'],
            'primary_phone' => ['nullable', new PhoneNumberForTenant()],
            'secondary_phone' => ['nullable', new PhoneNumberForTenant()],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'in:active,inactive,migrated'],
            'notes' => ['nullable', 'string'],

            // members (optional)
            'members' => ['nullable', 'array'],
            'members.*.first_name' => ['required_with:members', 'string', 'max:120'],
            'members.*.last_name' => ['required_with:members', 'string', 'max:120'],
            'members.*.phone' => ['nullable', new PhoneNumberForTenant()],
            'members.*.email' => ['nullable', 'email', 'max:255'],
        ];
    }
}


