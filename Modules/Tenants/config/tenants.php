<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenants Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for multi-tenant functionality including plans, limits,
    | and tenant-specific settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define the available subscription plans and their limits.
    |
    */
    'plans' => [
        'free' => [
            'name' => 'Free Plan',
            'max_users' => 10,
            'max_storage_mb' => 100,
            'features' => ['events'],
            'price' => 0,
        ],
        'basic' => [
            'name' => 'Basic Plan',
            'max_users' => 50,
            'max_storage_mb' => 1000,
            'features' => ['events', 'donations'],
            'price' => 29.99,
        ],
        'premium' => [
            'name' => 'Premium Plan',
            'max_users' => 100,
            'max_storage_mb' => 5000,
            'features' => ['events', 'donations', 'groups', 'messaging'],
            'price' => 99.99,
        ],
        'enterprise' => [
            'name' => 'Enterprise Plan',
            'max_users' => 999999,
            'max_storage_mb' => 50000,
            'features' => ['events', 'donations', 'groups', 'messaging', 'custom_branding', 'api_access', 'dedicated_support'],
            'price' => 299.99,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Available Features
    |--------------------------------------------------------------------------
    |
    | List of all available features that can be enabled per tenant.
    |
    */
    'available_features' => [
        'events' => 'Event Management',
        'donations' => 'Donation Tracking',
        'groups' => 'Group Management',
        'messaging' => 'Messaging & Notifications',
        'custom_branding' => 'Custom Branding',
        'api_access' => 'API Access',
        'dedicated_support' => 'Dedicated Support',
        'advanced_reporting' => 'Advanced Reporting',
        'multi_location' => 'Multi-Location Support',
        'volunteer_management' => 'Volunteer Management',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Settings
    |--------------------------------------------------------------------------
    |
    | Default settings for new tenants.
    |
    */
    'default_settings' => [
        'timezone' => 'America/Chicago',
        'language' => 'en',
        'currency' => 'USD',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trial Period
    |--------------------------------------------------------------------------
    |
    | Default trial period for new tenants (in days).
    |
    */
    'trial_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Domain Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant custom domains.
    |
    */
    'domain' => [
        'allow_custom_domains' => true,
        'require_ssl' => true,
        'base_domain' => env('TENANT_BASE_DOMAIN', 'ekklesia.app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Isolation
    |--------------------------------------------------------------------------
    |
    | Strict tenant data isolation settings.
    |
    */
    'isolation' => [
        'strict_mode' => true,
        'allow_cross_tenant_access' => false,
        'super_admin_bypass' => true, // Allow SuperAdmin to access all tenants
    ],

    /*
    |--------------------------------------------------------------------------
    | Colors
    |--------------------------------------------------------------------------
    |
    | Default branding colors for tenants.
    |
    */
    'default_colors' => [
        'primary' => '#3B82F6',
        'secondary' => '#10B981',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Global limits for tenant creation and management.
    |
    */
    'limits' => [
        'max_tenants' => env('MAX_TENANTS', 1000),
        'max_name_length' => 255,
        'max_slug_length' => 255,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Delete Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for soft deletes.
    |
    */
    'soft_deletes' => [
        'enabled' => true,
        'auto_delete_after_days' => 90, // Permanently delete after 90 days
    ],

];

