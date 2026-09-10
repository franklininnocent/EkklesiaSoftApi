<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Tenants\Models\Scopes\TenantScope;
use Modules\Tenants\Models\SubscriptionSettings;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionService;

/**
 * Deterministic subscription read-only E2E fixtures (isolated parishes).
 *
 * Login password for seeded admins: password
 *
 * Run:
 * php artisan db:seed --class=Modules\\Tenants\\Database\\Seeders\\SubscriptionExpiredE2eSeeder
 */
class SubscriptionExpiredE2eSeeder extends Seeder
{
    public const MARKER = 'subscription_expired_e2e_v1';

    public const EXPIRED_SLUG = 'e2e-expired-parish';

    public const GRACE_SLUG = 'e2e-grace-parish';

    public const EXPIRED_ADMIN_EMAIL = 'e2e-expired-admin@example.test';

    public const GRACE_ADMIN_EMAIL = 'e2e-grace-admin@example.test';

    public const EXPIRED_BCC_ID = 'e2e90001-0001-4000-8000-000000000010';

    public const EXPIRED_FAMILY_ID = 'e2e90001-0001-4000-8000-000000000001';

    public const EXPIRED_PERSON_ID = 'e2e90002-0001-4000-8000-000000000020';

    public const EXPIRED_MEMBER_ID = 'e2e90003-0001-4000-8000-000000000020';

    public const EXPIRED_FAMILY_NAME = 'E2E Expired Family';

    public const EXPIRED_CERTIFICATE_NUMBER = 'E2E-EXPIRED-BAPTISM-001';

    /** @var list<string> */
    private const PARISH_PERMISSIONS = [
        'sacraments.view',
        'sacraments.create',
        'sacraments.edit',
        'sacraments.delete',
        'sacraments.void',
        'sacraments.restore',
        'sacraments.settings.view',
        'sacraments.settings.manage',
        'donations.view',
        'donations.collect',
        'donations.reverse',
        'donations.refund',
        'donations.manage',
        'bcc.view',
        'bcc.create',
        'bcc.edit',
        'bcc.delete',
        'bcc.manage_members',
        'church.settings.edit',
        'roles.view',
        'roles.create',
        'roles.update',
        'roles.delete',
        'roles.assign',
        'permissions.view',
        'permissions.assign',
        'users.view',
        'users.create',
        'families.view',
        'families.create',
        'families.edit',
        'families.delete',
        'ministries.view',
        'subscription.view',
    ];

    public function run(): void
    {
        $settings = SubscriptionSettings::current();
        $graceDays = max(0, (int) $settings->grace_period_days);

        $expiredTenant = $this->seedTenant(
            self::EXPIRED_SLUG,
            'E2E Expired Parish',
            now()->subDays($graceDays + 3),
            ['donations', 'events', 'groups'],
        );

        $graceTenant = $this->seedTenant(
            self::GRACE_SLUG,
            'E2E Grace Parish',
            now()->subDays(min(2, max($graceDays - 1, 1))),
            ['donations', 'events', 'groups'],
        );

        $expiredAdmin = $this->seedParishAdmin($expiredTenant, self::EXPIRED_ADMIN_EMAIL);
        $graceAdmin = $this->seedParishAdmin($graceTenant, self::GRACE_ADMIN_EMAIL);

        $this->seedParishData($expiredTenant, $expiredAdmin);

        $subscription = app(SubscriptionService::class);
        $expiredStatus = $subscription->resolveStatus($expiredTenant->fresh());
        $graceStatus = $subscription->resolveStatus($graceTenant->fresh());

        $this->command?->info('Subscription expired E2E fixtures ready.');
        $this->command?->info('Expired tenant id='.$expiredTenant->id.' status='.$expiredStatus.' access='.$subscription->accessMode($expiredTenant));
        $this->command?->info('Grace tenant id='.$graceTenant->id.' status='.$graceStatus.' access='.$subscription->accessMode($graceTenant));
        $this->command?->info('Expired admin: '.self::EXPIRED_ADMIN_EMAIL.' / password');
        $this->command?->info('Grace admin: '.self::GRACE_ADMIN_EMAIL.' / password');
        $this->command?->info('Set SUBSCRIPTION_EXPIRED_E2E_TENANT_ID='.$expiredTenant->id);
        $this->command?->info('Set SUBSCRIPTION_GRACE_E2E_TENANT_ID='.$graceTenant->id);
    }

    /**
     * @param  list<string>  $features
     */
    private function seedTenant(string $slug, string $name, \DateTimeInterface $subscriptionEndsAt, array $features): Tenant
    {
        return Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'active' => 1,
                'trial_ends_at' => null,
                'subscription_suspended_at' => null,
                'subscription_ends_at' => $subscriptionEndsAt,
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

    private function seedParishData(Tenant $tenant, User $admin): void
    {
        TenantScope::runWithout(function () use ($tenant, $admin): void {
            $this->upsertParishRecords($tenant, $admin);
        });
    }

    private function upsertParishRecords(Tenant $tenant, User $admin): void
    {
        $this->upsertRecord(BCC::class, ['id' => self::EXPIRED_BCC_ID], [
            'tenant_id' => $tenant->id,
            'bcc_code' => 'E2E-EXP',
            'name' => 'E2E Expired BCC',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'notes' => self::MARKER,
        ]);

        $this->upsertRecord(Family::class, ['id' => self::EXPIRED_FAMILY_ID], [
            'tenant_id' => $tenant->id,
            'bcc_id' => self::EXPIRED_BCC_ID,
            'family_code' => 'E2E-EXP-FAM',
            'family_name' => self::EXPIRED_FAMILY_NAME,
            'head_of_family' => 'E2E Expired Head',
            'status' => 'active',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'notes' => self::MARKER,
        ]);

        $this->upsertRecord(Person::class, ['id' => self::EXPIRED_PERSON_ID], [
            'tenant_id' => $tenant->id,
            'first_name' => 'E2E',
            'last_name' => 'ExpiredMember',
            'gender' => 'male',
            'date_of_birth' => '2000-01-15',
            'status' => 'active',
        ]);

        $this->upsertRecord(FamilyMember::class, ['id' => self::EXPIRED_MEMBER_ID], [
            'tenant_id' => $tenant->id,
            'family_id' => self::EXPIRED_FAMILY_ID,
            'person_id' => self::EXPIRED_PERSON_ID,
            'first_name' => 'E2E',
            'last_name' => 'ExpiredMember',
            'gender' => 'male',
            'date_of_birth' => '2000-01-15',
            'relationship_to_head' => 'self',
            'status' => 'active',
            'is_primary_contact' => true,
        ]);

        $baptismType = SacramentType::query()->where('code', 'baptism')->first()
            ?? SacramentType::factory()->create(['code' => 'baptism', 'name' => 'Baptism']);

        $this->upsertRecord(Sacrament::class, [
            'tenant_id' => $tenant->id,
            'certificate_number' => self::EXPIRED_CERTIFICATE_NUMBER,
        ], [
            'sacrament_type_id' => $baptismType->id,
            'family_id' => self::EXPIRED_FAMILY_ID,
            'bcc_id' => self::EXPIRED_BCC_ID,
            'recipient_name' => 'E2E Expired Baptism',
            'date_administered' => '2015-06-01',
            'place_administered' => 'E2E Parish Church',
            'minister_name' => 'Fr. E2E',
            'minister_title' => 'Fr.',
            'status' => SacramentStatus::REGISTERED,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'notes' => self::MARKER,
        ]);
    }

    /**
     * Idempotent upsert that restores soft-deleted fixture rows on re-seed.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertRecord(string $modelClass, array $keys, array $values): Model
    {
        $query = $modelClass::withoutGlobalScopes();
        if (method_exists($modelClass, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        /** @var Model|null $record */
        $record = $query->where($keys)->first();

        if ($record) {
            if (method_exists($record, 'trashed') && $record->trashed()) {
                $record->restore();
            }

            $record->fill($values);
            $record->save();

            return $record;
        }

        return $modelClass::withoutGlobalScopes()->create(array_merge($keys, $values));
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
                    'description' => 'Subscription E2E permission',
                    'module' => $this->permissionModuleFor($name),
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

    private function permissionModuleFor(string $name): string
    {
        $prefix = explode('.', $name)[0] ?? 'rbac';

        return match ($prefix) {
            'sacraments', 'certificate' => 'Sacraments',
            'donations' => 'Donations',
            'bcc' => 'BCC',
            'church' => 'ChurchSettings',
            'roles', 'permissions' => 'RolesAndPermissions',
            'users' => 'Authentication',
            'families' => 'Families',
            'members' => 'Members',
            'subscription' => 'Tenants',
            default => 'RBAC',
        };
    }
}
