<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roles Module Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for role management including system roles, custom roles,
    | and tenant-specific role settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | System Roles
    |--------------------------------------------------------------------------
    |
    | These are the core system roles that cannot be modified or deleted.
    | They are seeded automatically.
    |
    */
    'system_roles' => [
        'SuperAdmin' => [
            'level' => 1,
            'description' => 'Super Administrator with full system privileges',
            'protected' => true,
        ],
        'EkklesiaAdmin' => [
            'level' => 2,
            'description' => 'Tenant Administrator with management privileges',
            'protected' => true,
        ],
        'EkklesiaManager' => [
            'level' => 3,
            'description' => 'Tenant Manager with limited administrative access',
            'protected' => true,
        ],
        'EkklesiaUser' => [
            'level' => 4,
            'description' => 'Standard user with basic access',
            'protected' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Role Settings
    |--------------------------------------------------------------------------
    |
    | Settings for custom roles created by tenants.
    |
    */
    'custom_roles' => [
        'allow_creation' => true,
        'allow_deletion' => true,
        'allow_modification' => true,
        'min_level' => 5, // Custom roles start from level 5
        'max_level' => 10,
        'max_per_tenant' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Permissions
    |--------------------------------------------------------------------------
    |
    | Define who can manage roles.
    |
    */
    'permissions' => [
        'create' => ['SuperAdmin', 'EkklesiaAdmin', 'EkklesiaManager'],
        'update' => ['SuperAdmin', 'EkklesiaAdmin', 'EkklesiaManager'],
        'delete' => ['SuperAdmin', 'EkklesiaAdmin', 'EkklesiaManager'],
        'view' => ['SuperAdmin', 'EkklesiaAdmin', 'EkklesiaManager', 'EkklesiaUser'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Role Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for tenant-specific roles.
    |
    */
    'tenant_roles' => [
        'allow_custom_roles' => true,
        'inherit_global_roles' => true, // Tenants can use global system roles
        'isolated' => true, // Tenant roles are isolated from other tenants
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    |
    | Validation settings for role creation and updates.
    |
    */
    'validation' => [
        'name_min_length' => 3,
        'name_max_length' => 255,
        'description_max_length' => 1000,
        'require_description' => false,
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
        'auto_delete_after_days' => 90,
    ],

];

