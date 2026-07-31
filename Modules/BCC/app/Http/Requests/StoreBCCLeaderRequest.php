<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBCCLeaderRequest extends FormRequest
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
            // Leader Identification (at least one required)
            'user_id' => ['nullable', 'exists:users,id', 'required_without_all:family_member_id,leader_name'],
            'family_member_id' => ['nullable', 'exists:family_members,id', 'required_without_all:user_id,leader_name'],
            'leader_name' => ['required', 'string', 'max:255'],
            
            // Role Information
            'role' => ['required', 'string', 'max:100'],
            
            // Dates
            'assigned_date' => ['nullable', 'date', 'before_or_equal:today'],
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

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leader_name.required' => 'Leader name is required.',
            'role.required' => 'Leader role is required.',
            'end_date.after' => 'End date must be after the assigned date.',
        ];
    }
}


