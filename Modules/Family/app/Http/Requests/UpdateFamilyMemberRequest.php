<?php

namespace Modules\Family\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFamilyMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Personal Information
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            
            // Demographics
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'in:male,female,other'],
            'relationship_to_head' => ['sometimes', 'required', 'in:self,spouse,son,daughter,father,mother,brother,sister,grandfather,grandmother,grandson,granddaughter,uncle,aunt,nephew,niece,cousin,other'],
            'marital_status' => ['nullable', 'in:single,married,widowed,separated,divorced'],
            
            // Contact Information
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_primary_contact' => ['nullable', 'boolean'],
            
            // Sacrament Information
            'baptism_date' => ['nullable', 'date', 'before_or_equal:today'],
            'baptism_place' => ['nullable', 'string', 'max:255'],
            'first_communion_date' => ['nullable', 'date', 'before_or_equal:today'],
            'first_communion_place' => ['nullable', 'string', 'max:255'],
            'confirmation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'confirmation_place' => ['nullable', 'string', 'max:255'],
            'marriage_date' => ['nullable', 'date', 'before_or_equal:today'],
            'marriage_place' => ['nullable', 'string', 'max:255'],
            'marriage_spouse_name' => ['nullable', 'string', 'max:255'],
            
            // Additional Information
            'occupation' => ['nullable', 'string', 'max:255'],
            'education' => ['nullable', 'string', 'max:255'],
            'skills_talents' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            
            // Status
            'status' => ['nullable', 'in:active,inactive,deceased,migrated'],
            'deceased_date' => ['nullable', 'date', 'before_or_equal:today', 'required_if:status,deceased'],
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
            'first_name' => 'first name',
            'middle_name' => 'middle name',
            'last_name' => 'last name',
            'date_of_birth' => 'date of birth',
            'relationship_to_head' => 'relationship to family head',
            'marital_status' => 'marital status',
            'is_primary_contact' => 'primary contact',
        ];
    }
}


