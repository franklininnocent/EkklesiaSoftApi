<?php

namespace Modules\Subscriptions\Support;

use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\Role;
use Modules\RolesAndPermissions\Models\Permission;

/**
 * Idempotent platform permission catalog for subscription administration.
 *
 * Super Admin owns the catalog (publish/retire plans, features, policies).
 * Ekklesia Admin operates it (view, assign tenants, overrides, usage, audit, requests).
 */
final class SubscriptionsPermissionCatalog
{
    public const PLANS_VIEW = 'subscriptions.plans.view';

    public const PLANS_MANAGE = 'subscriptions.plans.manage';

    public const PLANS_PUBLISH = 'subscriptions.plans.publish';

    public const PLANS_DELETE = 'subscriptions.plans.delete';

    public const FEATURES_MANAGE = 'subscriptions.features.manage';

    public const TENANTS_MANAGE = 'subscriptions.tenants.manage';

    public const OVERRIDES_MANAGE = 'subscriptions.overrides.manage';

    public const USAGE_VIEW = 'subscriptions.usage.view';

    public const AUDIT_VIEW = 'subscriptions.audit.view';

    public const POLICIES_MANAGE = 'subscriptions.policies.manage';

    public const REQUESTS_REVIEW = 'subscriptions.requests.review';

    public const REVENUE_VIEW = 'subscriptions.revenue.view';

    /** @var list<string> */
    public const EKKLESIA_ADMIN_DEFAULT = [
        self::PLANS_VIEW,
        self::TENANTS_MANAGE,
        self::OVERRIDES_MANAGE,
        self::USAGE_VIEW,
        self::AUDIT_VIEW,
        self::REQUESTS_REVIEW,
    ];

    /**
     * @return array<string, array{display_name: string, description: string}>
     */
    public static function permissionDefinitions(): array
    {
        return [
            self::PLANS_VIEW => ['display_name' => 'View subscription plans', 'description' => 'View plans, versions and the feature matrix'],
            self::PLANS_MANAGE => ['display_name' => 'Manage subscription plans', 'description' => 'Create plans and edit draft plan versions'],
            self::PLANS_PUBLISH => ['display_name' => 'Publish subscription plans', 'description' => 'Publish, schedule and retire plan versions; archive plans'],
            self::PLANS_DELETE => ['display_name' => 'Delete subscription plans', 'description' => 'Permanently remove unused plans from the catalog (Super Admin only)'],
            self::FEATURES_MANAGE => ['display_name' => 'Manage feature catalog', 'description' => 'Create and edit features and dependencies'],
            self::TENANTS_MANAGE => ['display_name' => 'Manage tenant subscriptions', 'description' => 'Assign and change tenant plans'],
            self::OVERRIDES_MANAGE => ['display_name' => 'Manage entitlement overrides', 'description' => 'Grant or revoke tenant-specific entitlement overrides'],
            self::USAGE_VIEW => ['display_name' => 'View subscription usage', 'description' => 'View tenant usage against plan limits'],
            self::AUDIT_VIEW => ['display_name' => 'View subscription audit', 'description' => 'View subscription and catalog audit history'],
            self::POLICIES_MANAGE => ['display_name' => 'Manage subscription policies', 'description' => 'Edit default plan, thresholds, trial and tax policies'],
            self::REQUESTS_REVIEW => ['display_name' => 'Review upgrade requests', 'description' => 'Approve or reject tenant upgrade requests'],
            self::REVENUE_VIEW => ['display_name' => 'View contracted revenue', 'description' => 'View contracted subscription revenue (MRR / ARR)'],
        ];
    }

    public static function syncPermissionsAndRoles(): void
    {
        $now = now();
        $permissionIds = [];

        foreach (self::permissionDefinitions() as $name => $meta) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $meta['display_name'],
                    'description' => $meta['description'],
                    'module' => 'Subscriptions',
                    'category' => 'subscriptions',
                    'tenant_id' => null,
                    'is_custom' => 0,
                    'scope' => Permission::SCOPE_PLATFORM,
                    'active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
            $permissionIds[$name] = $permission->id;
        }

        $superAdmin = Role::query()->where('name', Role::SUPER_ADMIN)->first();
        if ($superAdmin) {
            self::syncRolePermissions($superAdmin, array_values($permissionIds));
        }

        $ekklesiaAdmin = Role::query()->where('name', Role::EKKLESIA_ADMIN)->first();
        if ($ekklesiaAdmin) {
            self::syncRolePermissions(
                $ekklesiaAdmin,
                array_map(static fn (string $name) => $permissionIds[$name], self::EKKLESIA_ADMIN_DEFAULT)
            );
        }
    }

    /**
     * @param  list<int|string>  $permissionIds
     */
    private static function syncRolePermissions(Role $role, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
