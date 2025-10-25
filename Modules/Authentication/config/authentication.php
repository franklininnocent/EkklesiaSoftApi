<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Authentication module including roles, permissions,
    | and security settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Roles
    |--------------------------------------------------------------------------
    |
    | Define the default roles for the application. These roles are seeded
    | automatically when running the module seeders.
    |
    */
    'roles' => [
        'super_admin' => [
            'name' => 'SuperAdmin',
            'description' => 'Super Administrator of the Application with full system privileges',
            'level' => 1,
        ],
        'ekklesia_admin' => [
            'name' => 'EkklesiaAdmin',
            'description' => 'Administrator of the Application with tenant management privileges',
            'level' => 2,
        ],
        'ekklesia_manager' => [
            'name' => 'EkklesiaManager',
            'description' => 'Manager of the Application with limited administrative access',
            'level' => 3,
        ],
        'ekklesia_user' => [
            'name' => 'EkklesiaUser',
            'description' => 'Standard user of the Application with basic access',
            'level' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Role Assignment
    |--------------------------------------------------------------------------
    |
    | The default role to assign to new users during registration if no role
    | is specified.
    |
    */
    'default_role' => 'EkklesiaUser',
    'default_role_id' => 4,

    /*
    |--------------------------------------------------------------------------
    | Super Admin Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the default Super Admin user that is seeded during
    | initial setup.
    |
    */
    'super_admin' => [
        'name' => 'Franklin Innocent F',
        'email' => 'franklininnocent.fs@gmail.com',
        'password' => 'Secrete*999', // This will be hashed during seeding
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    |
    | Security-related configuration for authentication.
    |
    */
    'security' => [
        // Require email verification for new users
        'require_email_verification' => false,

        // Check if user is active before allowing login
        'check_active_status' => true,

        // Check if user's role is active before allowing login
        'check_role_active_status' => true,

        // Automatically logout users when they are deactivated
        'auto_logout_on_deactivate' => true,

        // Password minimum length
        'password_min_length' => 8,

        // Password requires confirmation during registration
        'password_confirmation_required' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Delete Configuration
    |--------------------------------------------------------------------------
    |
    | Enable soft deletes for users and roles to maintain data integrity
    | and support audit trails.
    |
    */
    'soft_deletes' => [
        'enabled' => true,
        'force_delete_after_days' => 90, // Permanently delete after 90 days (optional)
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for multi-tenant support in the authentication system.
    |
    */
    'multi_tenant' => [
        'enabled' => true,
        'super_admin_has_no_tenant' => true, // Super Admin has global access
        'tenant_isolation_strict' => true, // Strictly enforce tenant data isolation
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for API tokens (Laravel Passport).
    |
    */
    'token' => [
        'name' => 'API Token',
        'expires_in_days' => 365, // Token expiration (optional, based on Passport config)
    ],

];

