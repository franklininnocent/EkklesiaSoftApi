<?php

namespace Modules\BCC\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBCCRequest extends FormRequest
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
            // BCC Information
            'name' => ['required', 'string', 'max:255'],
            'bcc_code' => ['nullable', 'string', 'max:50'], // Auto-generated if not provided
            'description' => ['nullable', 'string'],
            
            // Location
            'meeting_place' => ['nullable', 'string', 'max:255'],
            
            // Meeting Schedule
            'meeting_day' => ['nullable', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'meeting_time' => ['nullable', 'date_format:H:i'],
            'meeting_frequency' => ['nullable', 'string', 'max:100'],
            
            // Capacity
            'min_families' => ['nullable', 'integer', 'min:1'],
            'max_families' => ['nullable', 'integer', 'min:1', 'gte:min_families'],
            
            // Contact Information
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            
            // Status and Dates
            'status' => ['nullable', 'in:active,inactive,suspended'],
            'established_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
            
            // Leaders (optional nested array)
            'leaders' => ['nullable', 'array'],
            'leaders.*.user_id' => ['nullable', 'exists:users,id'],
            'leaders.*.family_member_id' => ['nullable', 'exists:family_members,id'],
            'leaders.*.leader_name' => ['required_with:leaders', 'string', 'max:255'],
            'leaders.*.role' => ['required_with:leaders', 'string', 'max:100'],
            'leaders.*.assigned_date' => ['nullable', 'date'],
            'leaders.*.responsibilities' => ['nullable', 'string'],
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
            'name' => 'BCC name',
            'bcc_code' => 'BCC code',
            'meeting_place' => 'meeting place',
            'meeting_day' => 'meeting day',
            'meeting_time' => 'meeting time',
            'meeting_frequency' => 'meeting frequency',
            'min_families' => 'minimum families',
            'max_families' => 'maximum families',
            'contact_phone' => 'contact phone',
            'contact_email' => 'contact email',
            'established_date' => 'established date',
            'leaders.*.leader_name' => 'leader name',
            'leaders.*.role' => 'leader role',
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
            'name.required' => 'The BCC name is required.',
            'max_families.gte' => 'Maximum families must be greater than or equal to minimum families.',
            'leaders.*.leader_name.required_with' => 'Leader name is required when adding leaders.',
            'leaders.*.role.required_with' => 'Leader role is required when adding leaders.',
        ];
    }
}


