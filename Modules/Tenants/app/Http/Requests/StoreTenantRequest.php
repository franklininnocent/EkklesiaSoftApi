<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Basic tenant information
            'tenant_name' => 'required|string|max:255|unique:tenants,name',
            'slogan' => 'nullable|string|max:500',
            'slug' => 'nullable|string|max:255|alpha_dash|unique:tenants,slug',
            'domain' => 'nullable|string|max:255|unique:tenants,domain',
            'plan' => 'nullable|string|in:free,basic,premium,enterprise',
            
            // Tenant Official Address (Mandatory) - NEW STRUCTURE
            'tenant_official_address' => 'required|array',
            'tenant_official_address.line1' => 'required|string|max:255',
            'tenant_official_address.line2' => 'nullable|string|max:255',
            'tenant_official_address.country_id' => 'required|integer|exists:countries,id',
            'tenant_official_address.state_id' => 'required|integer|exists:states,id',
            'tenant_official_address.district' => 'required|string|max:100',
            'tenant_official_address.pin_zip_code' => 'required|string|max:20',
            
            // Primary user details (Mandatory)
            'primary_user_name' => 'required|string|max:255',
            'primary_user_email' => 'required|email|max:255|unique:users,email',
            'primary_contact_number' => 'required|string|max:20',
            
            // Primary User Address (Mandatory) - NEW STRUCTURE
            'primary_user_address' => 'required|array',
            'primary_user_address.line1' => 'required|string|max:255',
            'primary_user_address.line2' => 'nullable|string|max:255',
            'primary_user_address.country_id' => 'required|integer|exists:countries,id',
            'primary_user_address.state_id' => 'required|integer|exists:states,id',
            'primary_user_address.district' => 'required|string|max:100',
            'primary_user_address.pin_zip_code' => 'required|string|max:20',
            
            // Secondary user details (Optional)
            'secondary_user_name' => 'nullable|string|max:255',
            'secondary_user_email' => 'nullable|email|max:255|unique:users,email',
            'secondary_contact_number' => 'nullable|string|max:20',
            
            // Secondary User Address (Optional)
            'secondary_user_address' => 'nullable|array',
            'secondary_user_address.line1' => 'nullable|string|max:255',
            'secondary_user_address.line2' => 'nullable|string|max:255',
            'secondary_user_address.district' => 'nullable|string|max:100',
            'secondary_user_address.state_province' => 'nullable|string|max:100',
            'secondary_user_address.country' => 'nullable|string|max:100',
            'secondary_user_address.pin_zip_code' => 'nullable|string|max:20',
            
            // Logo/Branding
            'tenant_logo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
            'primary_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            'secondary_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            
            // Subscription & limits
            'max_users' => 'nullable|integer|min:1|max:10000',
            'max_storage_mb' => 'nullable|integer|min:10|max:1000000',
            'trial_ends_at' => 'nullable|date|after:today',
            'subscription_ends_at' => 'nullable|date|after:trial_ends_at',
            
            // Settings & features
            'settings' => 'nullable|array',
            'features' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'tenant_name.required' => 'Tenant name is required',
            'tenant_name.unique' => 'A tenant with this name already exists',
            'slogan.max' => 'Slogan must not exceed 500 characters',
            'tenant_official_address.required' => 'Tenant official address is required',
            'tenant_official_address.line1.required' => 'Tenant address line 1 is required',
            'tenant_official_address.country_id.required' => 'Tenant country is required',
            'tenant_official_address.country_id.exists' => 'Selected country is invalid',
            'tenant_official_address.state_id.required' => 'Tenant state/province is required',
            'tenant_official_address.state_id.exists' => 'Selected state/province is invalid',
            'tenant_official_address.district.required' => 'Tenant district/city is required',
            'tenant_official_address.pin_zip_code.required' => 'Tenant PIN/ZIP code is required',
            'primary_user_name.required' => 'Primary user name is required',
            'primary_user_email.required' => 'Primary user email is required',
            'primary_user_email.email' => 'Please provide a valid email address',
            'primary_user_email.unique' => 'This email is already registered',
            'primary_contact_number.required' => 'Primary contact number is required',
            'primary_user_address.required' => 'Primary user address is required',
            'primary_user_address.line1.required' => 'Address line 1 is required',
            'primary_user_address.country_id.required' => 'Country is required',
            'primary_user_address.country_id.exists' => 'Selected country is invalid',
            'primary_user_address.state_id.required' => 'State/Province is required',
            'primary_user_address.state_id.exists' => 'Selected state/province is invalid',
            'primary_user_address.district.required' => 'District/City is required',
            'primary_user_address.pin_zip_code.required' => 'PIN/ZIP code is required',
            'secondary_user_email.email' => 'Please provide a valid email address for secondary user',
            'secondary_user_email.unique' => 'This email is already registered',
            'tenant_logo.image' => 'Logo must be an image file',
            'tenant_logo.mimes' => 'Logo must be a JPEG, PNG, GIF, or WebP file',
            'tenant_logo.max' => 'Logo size must not exceed 5MB',
            'primary_color.regex' => 'Primary color must be a valid hex color code',
            'secondary_color.regex' => 'Secondary color must be a valid hex color code',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'tenant_name' => 'tenant name',
            'slogan' => 'slogan',
            'tenant_official_address' => 'tenant official address',
            'tenant_official_address.line1' => 'tenant address line 1',
            'tenant_official_address.line2' => 'tenant address line 2',
            'tenant_official_address.country_id' => 'tenant country',
            'tenant_official_address.state_id' => 'tenant state/province',
            'tenant_official_address.district' => 'tenant district/city',
            'tenant_official_address.pin_zip_code' => 'tenant PIN/ZIP code',
            'primary_user_name' => 'primary user name',
            'primary_user_email' => 'primary user email',
            'primary_contact_number' => 'primary contact number',
            'primary_user_address.line1' => 'address line 1',
            'primary_user_address.line2' => 'address line 2',
            'primary_user_address.country_id' => 'country',
            'primary_user_address.state_id' => 'state/province',
            'primary_user_address.district' => 'district/city',
            'primary_user_address.pin_zip_code' => 'PIN/ZIP code',
            'secondary_user_name' => 'secondary user name',
            'secondary_user_email' => 'secondary user email',
            'secondary_contact_number' => 'secondary contact number',
            'secondary_user_address.line1' => 'secondary address line 1',
            'secondary_user_address.line2' => 'secondary address line 2',
            'secondary_user_address.district' => 'secondary district',
            'secondary_user_address.state_province' => 'secondary state/province',
            'secondary_user_address.country' => 'secondary country',
            'secondary_user_address.pin_zip_code' => 'secondary PIN/ZIP code',
        ];
    }
}

