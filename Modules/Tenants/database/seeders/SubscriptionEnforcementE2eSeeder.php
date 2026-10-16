<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Tenants\Models\Scopes\TenantScope;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * E2E parishes for TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT=true.
 *
 * Password for admins: password
 *
 * php artisan db:seed --class=Modules\\Tenants\\Database\\Seeders\\SubscriptionEnforcementE2eSeeder
 */
class SubscriptionEnforcementE2eSeeder extends Seeder
{
    public const MARKER = 'subscription_enforcement_e2e_v1';

    public const VIEW_ONLY_SLUG = 'e2e-view-only-parish';

    public const LOCKED_SLUG = 'e2e-locked-parish';

    public const VIEW_ONLY_ADMIN_EMAIL = 'e2e-view-only-admin@example.test';

    public const LOCKED_ADMIN_EMAIL = 'e2e-locked-admin@example.test';

    public const VIEW_ONLY_BCC_ID = 'e2e90001-0001-4000-8000-000000000011';

    public const VIEW_ONLY_FAMILY_ID = 'e2e90001-0001-4000-8000-000000000002';

    public const VIEW_ONLY_FAMILY_NAME = 'E2E View-Only Family';

    /** @var list<string> */
    private const PARISH_PERMISSIONS = [
        'families.view',
        'families.create',
        'subscription.view',
        'support.tickets.view',
        'support.tickets.create',
        'donations.view',
        'donations.reports',
    ];

    public function run(): void
    {
        $settings = SubscriptionSettings::current();
        $graceDays = max(0, (int) $settings->grace_period_days);
        $viewOnlyDays = max(0, (int) ($settings->view_only_allowance_days ?? 90));

        $viewOnlyEndsAt = now()->subDays($graceDays + 5);
        $lockedEndsAt = now()->subDays($graceDays + $viewOnlyDays + 15);

        $viewOnlyTenant = $this->seedTenant(
            self::VIEW_ONLY_SLUG,
            'E2E View-Only Parish',
            $viewOnlyEndsAt,
            ['donations'],
            null,
            null,
        );

        $lockedTenant = $this->seedTenant(
            self::LOCKED_SLUG,
            'E2E Locked Parish',
            $lockedEndsAt,
            ['donations'],
            0,
            null,
        );

        $viewOnlyAdmin = $this->seedParishAdmin($viewOnlyTenant, self::VIEW_ONLY_ADMIN_EMAIL);
        $lockedAdmin = $this->seedParishAdmin($lockedTenant, self::LOCKED_ADMIN_EMAIL);

        $this->seedViewOnlyParishData($viewOnlyTenant, $viewOnlyAdmin);

        $subscription = app(SubscriptionService::class);
        $enforcementOn = $subscription->usesViewOnlyLockedEnforcement();

        $viewAccess = $subscription->evaluateAccess($viewOnlyTenant->fresh());
        $lockedAccess = $subscription->evaluateAccess($lockedTenant->fresh());

        $this->command?->info('Subscription enforcement E2E fixtures ready.');
        $this->command?->warn('TENANT_SUBSCRIPTION_VIEW_ONLY_LOCKED_ENFORCEMENT is '.($enforcementOn ? 'ON' : 'OFF').' — expected access modes apply only when ON.');
        $this->command?->info('View-only tenant id='.$viewOnlyTenant->id.' access='.$viewAccess['access_mode'].' (expect view_only when enforcement ON)');
        $this->command?->info('Locked tenant id='.$lockedTenant->id.' access='.$lockedAccess['access_mode'].' (expect locked when enforcement ON)');
        $this->command?->info('View-only admin: '.self::VIEW_ONLY_ADMIN_EMAIL.' / password');
        $this->command?->info('Locked admin: '.self::LOCKED_ADMIN_EMAIL.' / password');
        $this->command?->info('Set SUBSCRIPTION_VIEW_ONLY_E2E_TENANT_ID='.$viewOnlyTenant->id);
        $this->command?->info('Set SUBSCRIPTION_LOCKED_E2E_TENANT_ID='.$lockedTenant->id);
        $this->command?->info('Then save Playwright storage: .auth/view-only-tenant-admin.json and .auth/locked-tenant-admin.json');
    }

    private function seedTenant(
        string $slug,
        string $name,
        \DateTimeInterface $subscriptionEndsAt,
        array $features,
        ?int $customViewOnlyAllowanceDays,
        ?int $customGraceDays,
    ): Tenant {
        return Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'active' => 1,
                'trial_ends_at' => null,
                'subscription_suspended_at' => null,
                'subscription_ends_at' => $subscriptionEndsAt,
                'custom_view_only_allowance_days' => $customViewOnlyAllowanceDays,
                'custom_grace_period_days' => $customGraceDays,
                'view_only_grandfather_until' => null,
                'features' => $features,
                'tenant_tier' => 'parish',
                'plan' => 'enterprise',
            ],
        );
    }

    private function seedParishAdmin(Tenant $tenant, string $email): User
    {
        $role = Role::query()->firstOrCreate(
            [
                'name' => Role::TENANT_ADMINISTRATOR,
                'tenant_id' => $tenant->id,
            ],
            [
                'description' => 'E2E parish administrator',
                'level' => 1,
                'active' => 1,
                'is_custom' => false,
                'role_type' => Role::ROLE_TYPE_TENANT,
                'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
            ]
        );

        $this->grantPermissions($role, self::PARISH_PERMISSIONS);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => 'E2E Parish Admin',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'active' => 1,
                'is_primary_admin' => true,
                'user_type' => 2,
                'email_verified_at' => now(),
            ]
        );

        $user->syncRoles([$role->id]);
        if (method_exists($role, 'clearUsersPermissionCache')) {
            $role->clearUsersPermissionCache();
        }

        return $user->fresh();
    }

    private function seedViewOnlyParishData(Tenant $tenant, User $admin): void
    {
        TenantScope::runWithout(function () use ($tenant, $admin): void {
            BCC::withoutGlobalScopes()->updateOrCreate(
                ['id' => self::VIEW_ONLY_BCC_ID],
                [
                    'tenant_id' => $tenant->id,
                    'bcc_code' => 'E2E-VO',
                    'name' => 'E2E View-Only BCC',
                    'status' => 'active',
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                    'notes' => self::MARKER,
                ]
            );

            Family::withoutGlobalScopes()->updateOrCreate(
                ['id' => self::VIEW_ONLY_FAMILY_ID],
                [
                    'tenant_id' => $tenant->id,
                    'bcc_id' => self::VIEW_ONLY_BCC_ID,
                    'family_code' => 'E2E-VO-FAM',
                    'family_name' => self::VIEW_ONLY_FAMILY_NAME,
                    'head_of_family' => 'E2E View-Only Head',
                    'status' => 'active',
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                    'notes' => self::MARKER,
                ]
            );
        });
    }

    /**
     * @param  list<string>  $names
     */
    private function grantPermissions(Role $role, array $names): void
    {
        $permissionIds = [];
        foreach ($names as $name) {
            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'description' => 'Subscription enforcement E2E permission',
                    'module' => 'RBAC',
                    'scope' => Permission::SCOPE_TENANT,
                    'category' => explode('.', $name)[0] ?? 'rbac',
                    'tenant_id' => null,
                    'is_custom' => false,
                    'active' => 1,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->syncWithoutDetaching($permissionIds);
    }
}
