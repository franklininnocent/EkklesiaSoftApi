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
            'features' => ['events', 'donations', 'ministries_associations'],
            'price' => 0,
        ],
        'basic' => [
            'name' => 'Basic Plan',
            'max_users' => 50,
            'max_storage_mb' => 1000,
            'features' => ['events', 'donations', 'ministries_associations'],
            'price' => 29.99,
        ],
        'premium' => [
            'name' => 'Premium Plan',
            'max_users' => 100,
            'max_storage_mb' => 5000,
            'features' => ['events', 'donations', 'groups', 'messaging', 'ministries_associations'],
            'price' => 99.99,
        ],
        'enterprise' => [
            'name' => 'Enterprise Plan',
            'max_users' => 999999,
            'max_storage_mb' => 50000,
            'features' => ['events', 'donations', 'groups', 'messaging', 'custom_branding', 'api_access', 'dedicated_support', 'ministries_associations'],
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
        'ministries_associations' => 'Ministries & Associations',
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
        'timezone' => 'Asia/Kolkata',
        'language' => 'en',
        'currency' => 'INR',
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
    | Subscription Access Control (R1)
    |--------------------------------------------------------------------------
    |
    | Runtime grace/expiring days are Super Admin–configurable in
    | subscription_settings. Values below are defaults / bootstrap only.
    |
    */
    'subscription' => [
        'grace_period_days' => 7,
        'expiring_warning_days' => 14,
        /*
        | legacy — Donations/Ministries blocked when expired; other modules writable.
        | read_only_when_expired — after grace, all parish mutations blocked; views allowed.
        */
        'write_policy' => env('TENANT_SUBSCRIPTION_WRITE_POLICY', 'read_only_when_expired'),
        'gated_modules' => [
            'donations',
            'ministries_associations',
            'groups',
            'messaging',
            'events',
        ],
        'always_on' => [
            'auth',
            'settings',
            'my_subscription',
            'church_profile',
        ],
        'read_only_write_allowlist' => [
            'api/admin/application-access*',
            'api/auth/refresh',
            'api/auth/logout',
            'api/tenant/subscription-access',
            'api/tenant/my-subscription',
            'api/tenant/support/*',
            'api/tenant/export/bulk',
            'api/tenant/export/bulk/*/download',
            'api/donations/webhooks/*',
            'api/persons/matches',
            'api/tenant/donations/reports/export',
            'api/sacraments/*/certificates/preview',
            'api/sacraments/*/certificates/latest/download',
            'api/sacraments/certificates/*/download',
            'api/sacraments/certificates/*/print',
        ],
        'lifecycle' => [
            'scheduler_enabled' => env('TENANT_SUBSCRIPTION_LIFECYCLE_ENABLED', true),
            'scheduler_time' => env('TENANT_SUBSCRIPTION_LIFECYCLE_TIME', '01:15'),
            'mail_enabled' => env('TENANT_SUBSCRIPTION_LIFECYCLE_MAIL', true),
        ],
    ],

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
        // When true, BelongsToTenant models auto-scope reads to TenantContext (fail-closed).
        'orm_global_scope' => env('TENANT_ORM_GLOBAL_SCOPE', true),
        // When true, API requests set transaction-scoped app.current_tenant for PostgreSQL RLS.
        'rls_enabled' => env('TENANT_RLS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Cache Versioning
    |--------------------------------------------------------------------------
    */
    'cache' => [
        // Bump tenant:{id}:cache_version on RBAC changes to invalidate versioned keys.
        'versioning_enabled' => env('TENANT_CACHE_VERSIONING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Queue Isolation
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'max_concurrent_per_tenant' => (int) env('TENANT_QUEUE_MAX_CONCURRENT', 1),
        'tenant_lock_seconds' => (int) env('TENANT_QUEUE_LOCK_SECONDS', 3600),
        'tenant_release_seconds' => (int) env('TENANT_QUEUE_RELEASE_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Private Storage
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'private_disk' => env('TENANT_PRIVATE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enterprise image media (shared ImageMediaService)
    |--------------------------------------------------------------------------
    */
    'media' => [
        'allowed_mimes' => ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'min_edge_px' => 64,
        'max_edge_px' => 4096,
        'max_pixels' => 8_000_000,
        'display_max_edge_px' => 800,
        'thumb_max_edge_px' => 128,
        'webp_quality' => 80,
        'ttl' => [
            'thumb_minutes' => (int) env('TENANT_MEDIA_TTL_THUMB_MINUTES', 10),
            'display_minutes' => (int) env('TENANT_MEDIA_TTL_DISPLAY_MINUTES', 15),
        ],
        'cache_max_age_seconds' => 300,
        'categories' => [
            'users' => ['max_bytes' => 3 * 1024 * 1024],
            'logos' => ['max_bytes' => 5 * 1024 * 1024],
            'families' => ['max_bytes' => 5 * 1024 * 1024],
            'bishops' => ['max_bytes' => 3 * 1024 * 1024],
            'patron' => ['max_bytes' => 3 * 1024 * 1024],
            'leadership' => ['max_bytes' => 3 * 1024 * 1024],
            'pope' => ['max_bytes' => 3 * 1024 * 1024],
        ],
        'dual_read_public_fallback' => (bool) env('TENANT_MEDIA_DUAL_READ_PUBLIC', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Data Export
    |--------------------------------------------------------------------------
    */
    'export' => [
        'disk' => env('TENANT_EXPORT_DISK', 'local'),
        'queue' => env('TENANT_EXPORT_QUEUE', 'tenant-exports'),
        'chunk_size' => (int) env('TENANT_EXPORT_CHUNK_SIZE', 500),
        'retention_days' => (int) env('TENANT_EXPORT_RETENTION_DAYS', 7),
        'max_active_per_tenant' => (int) env('TENANT_EXPORT_MAX_ACTIVE', 1),
        'max_concurrent_global' => (int) env('TENANT_EXPORT_MAX_CONCURRENT_GLOBAL', 2),
        'job_timeout' => (int) env('TENANT_EXPORT_JOB_TIMEOUT', 1800),
        'failed_temp_retention_hours' => (int) env('TENANT_EXPORT_FAILED_TEMP_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Pagination & Rate Limiting (Phase 7)
    |--------------------------------------------------------------------------
    */
    'api' => [
        'pagination' => [
            'enabled' => env('TENANT_API_PAGINATION_ENABLED', true),
            'default_per_page' => (int) env('TENANT_API_DEFAULT_PER_PAGE', 20),
            'max_per_page' => (int) env('TENANT_API_MAX_PER_PAGE', 100),
        ],
        'rate_limit' => [
            'enabled' => env('TENANT_API_RATE_LIMIT_ENABLED', true),
            'buckets' => [
                'default' => [
                    'max_attempts' => (int) env('TENANT_API_RATE_LIMIT_DEFAULT', 120),
                    'decay_seconds' => (int) env('TENANT_API_RATE_LIMIT_DECAY_SECONDS', 60),
                ],
                'auth' => [
                    'max_attempts' => (int) env('TENANT_API_RATE_LIMIT_AUTH', 10),
                    'decay_seconds' => (int) env('TENANT_API_RATE_LIMIT_AUTH_DECAY_SECONDS', 60),
                ],
                'uploads' => [
                    'max_attempts' => (int) env('TENANT_API_RATE_LIMIT_UPLOADS', 20),
                    'decay_seconds' => (int) env('TENANT_API_RATE_LIMIT_UPLOADS_DECAY_SECONDS', 60),
                ],
                'media_serve' => [
                    'max_attempts' => (int) env('TENANT_API_RATE_LIMIT_MEDIA_SERVE', 240),
                    'decay_seconds' => (int) env('TENANT_API_RATE_LIMIT_MEDIA_SERVE_DECAY_SECONDS', 60),
                ],
                'application_access' => [
                    'max_attempts' => (int) env('APPLICATION_ACCESS_API_RATE_LIMIT', 60),
                    'decay_seconds' => (int) env('APPLICATION_ACCESS_API_RATE_LIMIT_DECAY_SECONDS', 60),
                ],
                'application_access_stream' => [
                    'max_attempts' => (int) env('APPLICATION_ACCESS_STREAM_RATE_LIMIT', 10),
                    'decay_seconds' => (int) env('APPLICATION_ACCESS_STREAM_RATE_LIMIT_DECAY_SECONDS', 60),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Load testing (Phase 8)
    |--------------------------------------------------------------------------
    |
    | Bulk fixture seeding and in-process API benchmarks for staging/perf runs.
    | Not intended for production databases.
    |
    */
    'load_test' => [
        'default_tenants' => (int) env('LOAD_TEST_TENANTS', 100),
        'default_members' => (int) env('LOAD_TEST_MEMBERS', 500000),
        'members_per_family' => (int) env('LOAD_TEST_MEMBERS_PER_FAMILY', 5),
        'chunk_size' => (int) env('LOAD_TEST_CHUNK_SIZE', 2000),
        'tag' => env('LOAD_TEST_TAG', 'load-test'),
        'benchmark' => [
            'warmup' => (int) env('LOAD_TEST_BENCHMARK_WARMUP', 5),
            'iterations' => (int) env('LOAD_TEST_BENCHMARK_ITERATIONS', 30),
            'scenarios' => [
                'families.index' => [
                    'method' => 'GET',
                    'path' => '/api/families',
                    'query' => ['per_page' => 25],
                ],
                'donations.dashboard.summary' => [
                    'method' => 'GET',
                    'path' => '/api/tenant/donations/dashboard/summary',
                ],
                'sacraments.dashboard.summary' => [
                    'method' => 'GET',
                    'path' => '/api/sacraments/dashboard/summary',
                ],
            ],
            'thresholds' => [
                'families.index' => [
                    'p95_ms' => (int) env('LOAD_TEST_THRESHOLD_FAMILIES_P95_MS', 800),
                    'max_queries' => (int) env('LOAD_TEST_THRESHOLD_FAMILIES_MAX_QUERIES', 25),
                ],
                'donations.dashboard.summary' => [
                    'p95_ms' => (int) env('LOAD_TEST_THRESHOLD_DONATIONS_P95_MS', 1200),
                    'max_queries' => (int) env('LOAD_TEST_THRESHOLD_DONATIONS_MAX_QUERIES', 120),
                ],
                'sacraments.dashboard.summary' => [
                    'p95_ms' => (int) env('LOAD_TEST_THRESHOLD_SACRAMENTS_P95_MS', 1200),
                    'max_queries' => (int) env('LOAD_TEST_THRESHOLD_SACRAMENTS_MAX_QUERIES', 80),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform observability & production hardening (Phase 10)
    |--------------------------------------------------------------------------
    */
    'platform' => [
        'health' => [
            'token' => env('PLATFORM_HEALTH_TOKEN'),
        ],
        'audit' => [
            'enabled' => (bool) env('PLATFORM_AUDIT_ENABLED', true),
            'log_channel' => env('PLATFORM_AUDIT_LOG_CHANNEL', 'platform'),
        ],
        'security_audit' => [
            'enabled' => (bool) env('PLATFORM_SECURITY_AUDIT_ENABLED', true),
        ],
        'slow_query' => [
            'threshold_ms' => (int) env('PLATFORM_SLOW_QUERY_MS', 0),
            'log_channel' => env('PLATFORM_SLOW_QUERY_LOG_CHANNEL', 'platform'),
            'audit_log' => (bool) env('PLATFORM_SLOW_QUERY_AUDIT_LOG', false),
        ],
        'scheduler' => [
            'export_cleanup_enabled' => (bool) env('PLATFORM_SCHEDULE_EXPORT_CLEANUP', true),
            'export_cleanup_time' => env('PLATFORM_SCHEDULE_EXPORT_CLEANUP_TIME', '02:30'),
            'audit_cleanup_enabled' => (bool) env('PLATFORM_SCHEDULE_AUDIT_CLEANUP', true),
            'audit_cleanup_day' => env('PLATFORM_SCHEDULE_AUDIT_CLEANUP_DAY', 'sunday'),
            'audit_cleanup_time' => env('PLATFORM_SCHEDULE_AUDIT_CLEANUP_TIME', '03:00'),
        ],
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

