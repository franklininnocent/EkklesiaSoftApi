<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBCCLeaderRequest extends FormRequest
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
            // Leader Identification
            'user_id' => ['nullable', 'exists:users,id'],
            'family_member_id' => ['nullable', 'exists:family_members,id'],
            'leader_name' => ['sometimes', 'required', 'string', 'max:255'],
            
            // Role Information
            'role' => ['sometimes', 'required', 'string', 'max:100'],
            
            // Dates
            'assigned_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:assigned_date'],
            
            // Status
            'is_current' => ['nullable', 'boolean'],
            
            // Additional Information
            'responsibilities' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
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
            'user_id' => 'user',
            'family_member_id' => 'family member',
            'leader_name' => 'leader name',
            'role' => 'leader role',
            'assigned_date' => 'assigned date',
            'end_date' => 'end date',
            'is_current' => 'current status',
        ];
    }
}


