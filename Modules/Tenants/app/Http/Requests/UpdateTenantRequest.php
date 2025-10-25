<?php

namespace Modules\Tenants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
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
        $tenantId = $this->route('id');
        
        return [
            // Basic tenant information
            'tenant_name' => 'sometimes|required|string|max:255|unique:tenants,name,' . $tenantId,
            'slogan' => 'nullable|string|max:500',
            'slug' => 'nullable|string|max:255|alpha_dash|unique:tenants,slug,' . $tenantId,
            'domain' => 'nullable|string|max:255|unique:tenants,domain,' . $tenantId,
            'plan' => 'nullable|string|in:free,basic,premium,enterprise',
            
            // Tenant Official Address
            'tenant_official_address' => 'sometimes|required|array',
            'tenant_official_address.line1' => 'sometimes|required|string|max:255',
            'tenant_official_address.line2' => 'nullable|string|max:255',
            'tenant_official_address.district' => 'sometimes|required|string|max:100',
            'tenant_official_address.state_province' => 'sometimes|required|string|max:100',
            'tenant_official_address.country' => 'sometimes|required|string|max:100',
            'tenant_official_address.pin_zip_code' => 'sometimes|required|string|max:20',
            
            // Primary user details
            'primary_user_name' => 'sometimes|required|string|max:255',
            'primary_user_email' => 'sometimes|required|email|max:255',
            'primary_contact_number' => 'sometimes|required|string|max:20',
            
            // Primary User Address
            'primary_user_address' => 'sometimes|required|array',
            'primary_user_address.line1' => 'sometimes|required|string|max:255',
            'primary_user_address.line2' => 'nullable|string|max:255',
            'primary_user_address.district' => 'sometimes|required|string|max:100',
            'primary_user_address.state_province' => 'sometimes|required|string|max:100',
            'primary_user_address.country' => 'sometimes|required|string|max:100',
            'primary_user_address.pin_zip_code' => 'sometimes|required|string|max:20',
            
            // Secondary user details (Optional)
            'secondary_user_name' => 'nullable|string|max:255',
            'secondary_user_email' => 'nullable|email|max:255',
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
            
            // Status
            'active' => 'nullable|boolean',
            
            // Subscription & limits
            'max_users' => 'nullable|integer|min:1|max:10000',
            'max_storage_mb' => 'nullable|integer|min:10|max:1000000',
            'trial_ends_at' => 'nullable|date',
            'subscription_ends_at' => 'nullable|date',
            
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
            'primary_user_email.email' => 'Please provide a valid email address',
            'secondary_user_email.email' => 'Please provide a valid email address for secondary user',
            'primary_user_address.line1.required' => 'Address line 1 is required',
            'primary_user_address.district.required' => 'District is required',
            'primary_user_address.state_province.required' => 'State/Province is required',
            'primary_user_address.country.required' => 'Country is required',
            'primary_user_address.pin_zip_code.required' => 'PIN/ZIP code is required',
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
            'tenant_official_address.district' => 'tenant district',
            'tenant_official_address.state_province' => 'tenant state/province',
            'tenant_official_address.country' => 'tenant country',
            'tenant_official_address.pin_zip_code' => 'tenant PIN/ZIP code',
            'primary_user_name' => 'primary user name',
            'primary_user_email' => 'primary user email',
            'primary_contact_number' => 'primary contact number',
            'primary_user_address.line1' => 'address line 1',
            'primary_user_address.district' => 'district',
            'primary_user_address.state_province' => 'state/province',
            'primary_user_address.country' => 'country',
            'primary_user_address.pin_zip_code' => 'PIN/ZIP code',
            'secondary_user_name' => 'secondary user name',
            'secondary_user_email' => 'secondary user email',
            'secondary_contact_number' => 'secondary contact number',
            'secondary_user_address.line1' => 'secondary address line 1',
            'secondary_user_address.district' => 'secondary district',
            'secondary_user_address.state_province' => 'secondary state/province',
            'secondary_user_address.country' => 'secondary country',
            'secondary_user_address.pin_zip_code' => 'secondary PIN/ZIP code',
        ];
    }
}

