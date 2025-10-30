<?php

namespace Modules\Family\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFamilyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/policies
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Family Information
            'family_name' => ['sometimes', 'required', 'string', 'max:255'],
            'head_of_family' => ['nullable', 'string', 'max:255'],
            
            // Address Information
            'address_line_1' => ['nullable', 'string', 'max:500'],
            'address_line_2' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_id' => ['nullable', 'exists:states,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            
            // Contact Information
            'primary_phone' => ['nullable', 'string', 'max:20'],
            'secondary_phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            
            // BCC Assignment
            'bcc_id' => ['nullable', 'exists:bccs,id'],
            
            // Status
            'status' => ['nullable', 'in:active,inactive,migrated'],
            'notes' => ['nullable', 'string'],

            // Optimistic locking
            'updated_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'family_name' => 'family name',
            'head_of_family' => 'head of family',
            'primary_phone' => 'primary phone number',
            'secondary_phone' => 'secondary phone number',
            'bcc_id' => 'BCC',
            'updated_at' => 'last updated timestamp',
        ];
    }
}


