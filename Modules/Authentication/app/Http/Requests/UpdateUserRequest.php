<?php

namespace Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Authentication\Models\User;

/**
 * UpdateUserRequest - Validation for updating an existing user
 * 
 * This class validates the data required to update an existing user within a tenant.
 * 
 * @package Modules\Authentication\Http\Requests
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Authorization is handled in the controller
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $userId = $this->route('id'); // Get the user ID from the route

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                'min:2',
            ],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId), // Email must be unique except for current user
            ],
            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed', // Requires password_confirmation field
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', // Complex password
            ],
            'contact_number' => [
                'nullable',
                'string',
                'max:20',
                'regex:/^[\d\s\+\-\(\)]+$/', // Allow numbers, spaces, +, -, (, )
            ],
            'user_type' => [
                'nullable',
                'integer',
                'in:' . User::USER_TYPE_PRIMARY_CONTACT . ',' . User::USER_TYPE_SECONDARY_CONTACT,
            ],
            'role_ids' => [
                'sometimes',
                'array',
                'min:1', // At least one role must be assigned
            ],
            'role_ids.*' => [
                'required',
                'integer',
                'exists:roles,id', // Each role ID must exist in roles table
            ],
            'active' => [
                'nullable',
                'integer',
                'in:0,1',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'User name is required.',
            'name.min' => 'User name must be at least 2 characters.',
            'name.max' => 'User name cannot exceed 255 characters.',
            
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.unique' => 'This email address is already registered.',
            
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            
            'contact_number.regex' => 'Please provide a valid phone number.',
            'contact_number.max' => 'Contact number cannot exceed 20 characters.',
            
            'user_type.in' => 'Invalid user type. Must be 1 (Primary Contact) or 2 (Secondary Contact).',
            
            'role_ids.array' => 'Roles must be provided as an array.',
            'role_ids.min' => 'At least one role must be assigned to the user.',
            'role_ids.*.exists' => 'One or more selected roles do not exist.',
            
            'active.in' => 'Active status must be either 0 (Inactive) or 1 (Active).',
        ];
    }

    /**
     * Get custom attribute names for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'user name',
            'email' => 'email address',
            'password' => 'password',
            'contact_number' => 'contact number',
            'user_type' => 'user type',
            'role_ids' => 'roles',
            'role_ids.*' => 'role',
            'active' => 'active status',
        ];
    }
}

