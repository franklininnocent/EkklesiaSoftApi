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

    /*
    |--------------------------------------------------------------------------
    | Password Recovery (Forgot Password)
    |--------------------------------------------------------------------------
    */
    'recovery' => [
        'otp_ttl_seconds' => (int) env('AUTH_RECOVERY_OTP_TTL', 60),
        'reset_authorization_ttl_seconds' => (int) env('AUTH_RECOVERY_RESET_TTL', 300),
        'max_attempts' => (int) env('AUTH_RECOVERY_MAX_ATTEMPTS', 5),
        'daily_initiations' => (int) env('AUTH_RECOVERY_DAILY_LIMIT', 3),
        'recovery_block_seconds' => (int) env('AUTH_RECOVERY_BLOCK_SECONDS', 120),
        'resend_cooldown_seconds' => (int) env('AUTH_RECOVERY_RESEND_COOLDOWN', 60),
        'cleanup_retention_days' => (int) env('AUTH_RECOVERY_CLEANUP_DAYS', 7),
        'request_ttl_hours' => (int) env('AUTH_RECOVERY_REQUEST_TTL_HOURS', 24),
        'processing_stale_minutes' => (int) env('AUTH_RECOVERY_PROCESSING_STALE_MINUTES', 5),
        'pepper' => env('AUTH_RECOVERY_PEPPER'),
        'mail_enabled' => (bool) env('AUTH_RECOVERY_MAIL_ENABLED', true),
        'test_inbox_enabled' => (bool) env('AUTH_RECOVERY_TEST_INBOX', false),
        'mail_from' => [
            'address' => env('AUTH_RECOVERY_MAIL_FROM_ADDRESS', 'franklininnocent.fs@gmail.com'),
            'name' => env('AUTH_RECOVERY_MAIL_FROM_NAME', 'EkklesiaSoft'),
        ],
    ],

];

