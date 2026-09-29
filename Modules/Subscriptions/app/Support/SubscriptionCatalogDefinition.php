<?php

namespace Modules\Subscriptions\Support;

/**
 * Initial catalog seed data (features, dependencies, launch plans).
 *
 * Used only to seed the database on first install. Runtime decisions always read the
 * database catalog; Super Admin edits are never overwritten by re-seeding.
 */
final class SubscriptionCatalogDefinition
{
    public const DEFAULT_PLAN_CODE = 'STARTER';

    /**
     * @return list<array<string, mixed>>
     */
    public static function features(): array
    {
        $order = 0;
        $f = static function (
            string $code,
            string $name,
            string $category,
            ?string $module,
            string $description,
            array $extra = []
        ) use (&$order): array {
            $order += 10;

            return array_merge([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'category' => $category,
                'module_key' => $module,
                'feature_type' => 'BOOLEAN',
                'unit' => null,
                'legacy_key' => null,
                'is_core' => false,
                'legacy_default' => false,
                'is_public' => true,
                'display_order' => $order,
            ], $extra);
        };

        return [
            // Always available: safety floor that no plan can remove.
            $f('FAMILIES', 'Family Records', 'people', 'family', 'Create and manage family households.', ['is_core' => true]),
            $f('MEMBERS', 'Member Records', 'people', 'family', 'Maintain member profiles within families.', ['is_core' => true]),
            $f('CHURCH_PROFILE', 'Church Profile', 'administration', 'church_profile', 'Parish details, address and contact information.', ['is_core' => true]),
            $f('SACRAMENTS', 'Sacrament Records', 'people', 'sacraments', 'Baptism, confirmation, marriage and other sacrament records.', ['is_core' => true]),
            $f('NOTIFICATIONS', 'Notifications', 'communication', 'notifications', 'In-app and email notifications.', ['is_core' => true]),

            // Limits.
            $f('PEOPLE_LIMIT', 'People', 'limits', 'family', 'Maximum number of people (family members) on record.', ['feature_type' => 'LIMIT', 'unit' => 'people']),
            $f('FAMILY_LIMIT', 'Families', 'limits', 'family', 'Maximum number of family households.', ['feature_type' => 'LIMIT', 'unit' => 'families', 'is_public' => false]),
            $f('STAFF_USER_LIMIT', 'Staff Users', 'limits', 'users', 'Maximum number of staff user accounts.', ['feature_type' => 'LIMIT', 'unit' => 'users']),
            $f('STORAGE_MB', 'Storage', 'limits', 'media', 'Maximum file storage for photos and documents.', ['feature_type' => 'QUOTA', 'unit' => 'MB']),

            // Finance.
            $f('CONTRIBUTIONS', 'Contributions', 'finance', 'donations', 'Record family contributions and issue receipts.', ['legacy_key' => 'donations']),
            $f('CONTRIBUTION_PLANS', 'Contribution Plans', 'finance', 'donations', 'Recurring contribution plans and dues for families.', ['legacy_default' => true]),
            $f('FINANCIAL_DASHBOARD', 'Financial Dashboard', 'finance', 'donations', 'Collections, outstanding amounts and trends at a glance.', ['legacy_default' => true]),
            $f('ADVANCED_FINANCIAL_REPORTING', 'Advanced Financial Reporting', 'finance', 'donations', 'Detailed financial statements and reconciliation reports.', ['legacy_default' => true]),

            // Reporting.
            $f('BASIC_REPORTS', 'Basic Reports', 'reporting', 'reports', 'Standard family, member and sacrament reports.', ['legacy_default' => true]),
            $f('ADVANCED_REPORTS', 'Advanced Reports', 'reporting', 'reports', 'Filtered, grouped and exportable reports.', ['legacy_default' => true]),
            $f('ADVANCED_DASHBOARDS', 'Advanced Dashboards', 'reporting', 'dashboard', 'Additional dashboard widgets and insights.', ['legacy_default' => true]),
            $f('ADVANCED_ANALYTICS', 'Advanced Analytics', 'reporting', 'dashboard', 'Trends and analytics across parish activity.', ['legacy_default' => true]),
            $f('CUSTOM_REPORTING', 'Custom Reporting', 'reporting', 'reports', 'Custom report building for diocesan needs.'),

            // Ministries.
            $f('MINISTRIES', 'Ministries & Associations', 'ministries', 'ministries', 'Ministries, associations and their members.', ['legacy_key' => 'ministries_associations']),
            $f('ADVANCED_MINISTRY_MANAGEMENT', 'Advanced Ministry Management', 'ministries', 'ministries', 'Lifecycle, terms and advanced ministry administration.', ['legacy_default' => true]),
            $f('MASS_INTENTIONS', 'Mass Intentions', 'pastoral', 'mass_intentions', 'Record Mass intentions, schedule celebrations, and track fulfilment.', ['legacy_key' => 'mass_intentions']),

            // Governance.
            $f('CHURCH_LEADERSHIP', 'Church Leadership', 'governance', 'church_profile', 'Parish priests, council and leadership records.', ['legacy_default' => true]),
            $f('DIOCESE_BISHOPS', 'Diocese & Bishops', 'governance', 'ecclesiastical', 'Diocese, bishop and hierarchy information.', ['legacy_default' => true]),

            // Administration.
            $f('RBAC_BASIC', 'Roles & Permissions', 'administration', 'rbac', 'Standard staff roles and permissions.', ['legacy_default' => true]),
            $f('RBAC_ADVANCED', 'Advanced Roles & Permissions', 'administration', 'rbac', 'Custom roles with fine-grained permissions.', ['legacy_default' => true]),
            $f('AUDIT_LOG', 'Audit Log', 'administration', 'audit', 'Who changed what and when.', ['legacy_default' => true]),
            $f('IMPORT_EXPORT', 'Import & Export', 'administration', 'data', 'Bulk import and export of parish data.', ['legacy_default' => true]),
            $f('MULTIPLE_WORKFLOWS', 'Multiple Workflows', 'administration', 'workflows', 'Multiple approval and processing workflows.', ['legacy_default' => true]),
            $f('AUTOMATION', 'Automation', 'administration', 'automation', 'Scheduled reminders and automated tasks.', ['legacy_default' => true]),

            // Communication.
            $f('ADVANCED_COMMUNICATION', 'Advanced Communication', 'communication', 'notifications', 'Targeted announcements and communication tools.', ['legacy_default' => true]),
            $f('MESSAGING', 'Messaging', 'communication', 'messaging', 'Direct messaging with families and members.', ['legacy_key' => 'messaging', 'is_public' => false]),

            // Support.
            $f('PRIORITY_SUPPORT', 'Priority Support', 'support', 'support', 'Faster response from the Ekklesia support team.'),
            $f('DEDICATED_SUPPORT', 'Dedicated Support', 'support', 'support', 'A named support contact for your organisation.', ['legacy_key' => 'dedicated_support']),
            $f('DEDICATED_ONBOARDING', 'Dedicated Onboarding', 'support', 'support', 'Guided onboarding and data migration.'),

            // Enterprise.
            $f('MULTI_CAMPUS', 'Multiple Campuses', 'enterprise', 'tenants', 'Manage multiple campuses or branches.', ['legacy_key' => 'multi_location']),
            $f('DIOCESAN_DEPLOYMENT', 'Diocesan Deployment', 'enterprise', 'tenants', 'Diocese-wide deployment across parishes.'),
            $f('CHURCH_NETWORKS', 'Church Networks', 'enterprise', 'tenants', 'Connected networks of churches.'),
            $f('SSO', 'Single Sign-On', 'enterprise', 'authentication', 'Sign in with your organisation account.'),
            $f('ENTERPRISE_INTEGRATIONS', 'Enterprise Integrations', 'enterprise', 'integrations', 'Integrations with external systems.'),
            $f('API_ACCESS', 'API Access', 'enterprise', 'integrations', 'Programmatic access to parish data.', ['legacy_key' => 'api_access']),
            $f('CUSTOM_BRANDING', 'Custom Branding', 'enterprise', 'branding', 'Your logo and colours throughout the application.', ['legacy_key' => 'custom_branding']),

            // Legacy modules kept for grandfathered plans.
            $f('EVENTS', 'Events', 'legacy', 'events', 'Parish events.', ['legacy_key' => 'events', 'is_public' => false]),
            $f('GROUPS', 'Groups', 'legacy', 'groups', 'Parish groups.', ['legacy_key' => 'groups', 'is_public' => false]),
            $f('VOLUNTEER_MANAGEMENT', 'Volunteer Management', 'legacy', 'volunteers', 'Volunteer scheduling.', ['legacy_key' => 'volunteer_management', 'is_public' => false]),
        ];
    }

    /**
     * Feature => features it requires.
     *
     * @return array<string, list<string>>
     */
    public static function dependencies(): array
    {
        return [
            'CONTRIBUTION_PLANS' => ['CONTRIBUTIONS'],
            'FINANCIAL_DASHBOARD' => ['CONTRIBUTIONS'],
            'ADVANCED_FINANCIAL_REPORTING' => ['CONTRIBUTIONS', 'ADVANCED_REPORTS'],
            'ADVANCED_REPORTS' => ['BASIC_REPORTS'],
            'CUSTOM_REPORTING' => ['ADVANCED_REPORTS'],
            'ADVANCED_ANALYTICS' => ['ADVANCED_DASHBOARDS'],
            'ADVANCED_MINISTRY_MANAGEMENT' => ['MINISTRIES'],
            'RBAC_ADVANCED' => ['RBAC_BASIC'],
            'DIOCESAN_DEPLOYMENT' => ['DIOCESE_BISHOPS'],
        ];
    }

    /**
     * Launch plans. Feature lists are cumulative; limits: null = unlimited.
     *
     * @return list<array<string, mixed>>
     */
    public static function plans(): array
    {
        $starter = ['BASIC_REPORTS', 'CONTRIBUTIONS', 'RBAC_BASIC'];
        $standard = array_merge($starter, [
            'CONTRIBUTION_PLANS', 'FINANCIAL_DASHBOARD', 'MINISTRIES', 'RBAC_ADVANCED', 'ADVANCED_REPORTS',
            'CHURCH_LEADERSHIP', 'DIOCESE_BISHOPS', 'AUDIT_LOG', 'IMPORT_EXPORT', 'ADVANCED_DASHBOARDS',
        ]);
        $professional = array_merge($standard, [
            'ADVANCED_FINANCIAL_REPORTING', 'ADVANCED_MINISTRY_MANAGEMENT', 'ADVANCED_ANALYTICS', 'AUTOMATION',
            'ADVANCED_COMMUNICATION', 'MULTIPLE_WORKFLOWS', 'PRIORITY_SUPPORT',
        ]);
        $enterprise = array_merge($professional, [
            'MULTI_CAMPUS', 'DIOCESAN_DEPLOYMENT', 'CHURCH_NETWORKS', 'DEDICATED_ONBOARDING', 'DEDICATED_SUPPORT',
            'CUSTOM_REPORTING', 'SSO', 'ENTERPRISE_INTEGRATIONS', 'API_ACCESS', 'CUSTOM_BRANDING',
        ]);

        return [
            [
                'code' => 'STARTER',
                'key' => 'starter',
                'name' => 'Starter',
                'short_description' => 'Everything a small parish needs to keep family and sacrament records.',
                'pricing_type' => 'FIXED',
                'is_featured' => false,
                'badge_label' => null,
                'display_order' => 10,
                'version' => [
                    'monthly_price' => '1499.00',
                    'annual_price' => '14990.00',
                    'trial_days' => 30,
                    'billing_intervals' => ['MONTHLY', 'ANNUAL'],
                ],
                'features' => $starter,
                'limits' => ['PEOPLE_LIMIT' => 250, 'FAMILY_LIMIT' => null, 'STAFF_USER_LIMIT' => null, 'STORAGE_MB' => 2048],
            ],
            [
                'code' => 'STANDARD',
                'key' => 'standard',
                'name' => 'Standard',
                'short_description' => 'Contributions, ministries and leadership for growing parishes.',
                'pricing_type' => 'FIXED',
                'is_featured' => true,
                'badge_label' => 'Most Popular',
                'display_order' => 20,
                'version' => [
                    'monthly_price' => '2999.00',
                    'annual_price' => '29990.00',
                    'trial_days' => 30,
                    'billing_intervals' => ['MONTHLY', 'ANNUAL'],
                ],
                'features' => $standard,
                'limits' => ['PEOPLE_LIMIT' => 1000, 'FAMILY_LIMIT' => null, 'STAFF_USER_LIMIT' => null, 'STORAGE_MB' => 10240],
            ],
            [
                'code' => 'PROFESSIONAL',
                'key' => 'professional',
                'name' => 'Professional',
                'short_description' => 'Advanced finance, analytics and automation for large parishes.',
                'pricing_type' => 'FIXED',
                'is_featured' => false,
                'badge_label' => null,
                'display_order' => 30,
                'version' => [
                    'monthly_price' => '4999.00',
                    'annual_price' => '49990.00',
                    'trial_days' => 30,
                    'billing_intervals' => ['MONTHLY', 'ANNUAL'],
                ],
                'features' => $professional,
                'limits' => ['PEOPLE_LIMIT' => 2500, 'FAMILY_LIMIT' => null, 'STAFF_USER_LIMIT' => null, 'STORAGE_MB' => 25600],
            ],
            [
                'code' => 'ENTERPRISE',
                'key' => 'enterprise',
                'name' => 'Enterprise',
                'short_description' => 'Dioceses and church networks with 2,500+ people.',
                'pricing_type' => 'CUSTOM',
                'is_featured' => false,
                'badge_label' => null,
                'display_order' => 40,
                'version' => [
                    'monthly_price' => null,
                    'annual_price' => null,
                    'trial_days' => null,
                    'billing_intervals' => ['CUSTOM'],
                ],
                'features' => $enterprise,
                'limits' => ['PEOPLE_LIMIT' => null, 'FAMILY_LIMIT' => null, 'STAFF_USER_LIMIT' => null, 'STORAGE_MB' => null],
            ],
        ];
    }

    /**
     * Grandfathered legacy plan keys => catalog code.
     *
     * @return array<string, string>
     */
    public static function legacyPlanCodes(): array
    {
        return [
            'free' => 'LEGACY_FREE',
        ];
    }

    /**
     * Default policy document stored on subscription_settings.policies.
     *
     * @return array<string, mixed>
     */
    public static function defaultPolicies(): array
    {
        return [
            'usage_thresholds' => [70, 85, 95, 100],
            'over_limit_behavior' => 'BLOCK_NEW',
            'limit_exempt_flows' => ['sacrament_recipient'],
            'downgrade_behavior' => 'PRESERVE_DATA',
            'trial' => [
                'allow_trial_on_assignment' => true,
                'max_trial_days' => 90,
            ],
            'tax' => [
                'label' => 'GST',
                'rate_percent' => '18.00',
                'prices_include_tax' => false,
                'jurisdiction' => [
                    'country_code' => 'IN',
                    'tax_system' => 'GST',
                ],
            ],
            'currency_code' => 'INR',
            'upgrade_requests' => [
                'enabled' => true,
            ],
        ];
    }
}
