<?php

namespace Modules\Family\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFamilyRequest extends FormRequest
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
            'family_name' => ['required', 'string', 'max:255'],
            'head_of_family' => ['nullable', 'string', 'max:255'],
            'family_code' => ['nullable', 'string', 'max:50'], // Auto-generated if not provided
            
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
            
            // Members (optional nested array)
            'members' => ['nullable', 'array'],
            'members.*.first_name' => ['required_with:members', 'string', 'max:100'],
            'members.*.middle_name' => ['nullable', 'string', 'max:100'],
            'members.*.last_name' => ['required_with:members', 'string', 'max:100'],
            'members.*.date_of_birth' => ['nullable', 'date'],
            'members.*.gender' => ['nullable', 'in:male,female,other'],
            'members.*.relationship_to_head' => ['nullable', 'in:self,spouse,son,daughter,father,mother,brother,sister,grandfather,grandmother,grandson,granddaughter,uncle,aunt,nephew,niece,cousin,other'],
            'members.*.marital_status' => ['nullable', 'in:single,married,widowed,separated,divorced'],
            'members.*.phone' => ['nullable', 'string', 'max:20'],
            'members.*.email' => ['nullable', 'email', 'max:255'],
            'members.*.is_primary_contact' => ['nullable', 'boolean'],
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
            'members.*.first_name' => 'member first name',
            'members.*.last_name' => 'member last name',
            'members.*.date_of_birth' => 'member date of birth',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'family_name.required' => 'The family name is required.',
            'members.*.first_name.required_with' => 'Member first name is required when adding members.',
            'members.*.last_name.required_with' => 'Member last name is required when adding members.',
        ];
    }
}


