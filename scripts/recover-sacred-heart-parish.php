<?php

/**
 * One-off local recovery: Sacred Heart Church parish admin (shadmin@gmail.com).
 * Run: php scripts/recover-sacred-heart-parish.php
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Donations\Database\Seeders\DonationCategorySeeder;
use Modules\MinistriesAssociations\Database\Seeders\MinistriesAssociationsDefaultSeeder;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Tenants\Contracts\TenantPlanAssigner;
use Modules\Tenants\Models\SubscriptionPlan;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\AddressService;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Services\ChurchFinancialPeriodResolver;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const PARISH_NAME = 'Sacred Heart Church';
const ADMIN_EMAIL = 'shadmin@gmail.com';
const ADMIN_PASSWORD = 'TempPassword123!';

$existing = User::withTrashed()->where('email', ADMIN_EMAIL)->first();
if ($existing) {
    if ($existing->trashed()) {
        $existing->restore();
    }
    $existing->forceFill([
        'password' => Hash::make(ADMIN_PASSWORD),
        'active' => 1,
        'force_password_change' => false,
        'deleted_at' => null,
    ])->save();
    echo "Updated existing user #{$existing->id} ({$existing->email}) tenant_id={$existing->tenant_id}\n";
    exit(0);
}

$countryId = 101; // India (seeded)
$stateId = DB::table('states')->where('country_id', $countryId)->where('name', 'ILIKE', '%Tamil Nadu%')->value('id')
    ?? DB::table('states')->where('country_id', $countryId)->value('id');

if (! $stateId) {
    fwrite(STDERR, "No state found for country_id={$countryId}\n");
    exit(1);
}

$superAdmin = User::where('email', 'franklininnocent.fs@gmail.com')->first();
$createdBy = $superAdmin?->id;

DB::transaction(function () use ($countryId, $stateId, $createdBy) {
    $plan = 'enterprise';
    $planModel = SubscriptionPlan::query()->where('key', $plan)->first();
    $features = $planModel?->features
        ?? (array) (config('tenants.plans.enterprise.features') ?? ['donations', 'events', 'groups', 'ministries_associations']);

    $tenant = Tenant::create([
        'name' => PARISH_NAME,
        'slug' => Str::slug(PARISH_NAME),
        'plan' => $plan,
        'max_users' => 500,
        'max_storage_mb' => 10000,
        'trial_ends_at' => null,
        'subscription_ends_at' => null,
        'active' => 1,
        'primary_color' => '#3B82F6',
        'secondary_color' => '#10B981',
        'settings' => ['timezone' => 'Asia/Kolkata', 'language' => 'en'],
        'features' => $features,
        'created_by' => $createdBy,
    ]);

    $planAssigner = app()->bound(TenantPlanAssigner::class) ? app(TenantPlanAssigner::class) : null;
    if ($planAssigner) {
        $tenant = $planAssigner->assignInitialPlan($tenant, $plan, $createdBy, Role::SUPER_ADMIN);
    }

    $addressPayload = [
        'line1' => 'Sacred Heart Church Parish Office',
        'line2' => null,
        'country_id' => $countryId,
        'state_id' => $stateId,
        'district' => 'Chennai',
        'pin_zip_code' => '600001',
    ];

    $addressService = app(AddressService::class);
    $addressService->create($tenant, $addressPayload, 'official', true);
    app(ChurchCurrencyResolver::class)->syncDerivedColumns((int) $tenant->id);
    app(ChurchFinancialPeriodResolver::class)->syncDerivedFyStartColumns((int) $tenant->id);

    $maxId = (int) (DB::table('roles')->max('id') ?? 0);
    DB::statement('SELECT setval(\'roles_id_seq\', '.max($maxId + 1, 1).', true)');

    $adminRole = Role::create([
        'name' => Role::TENANT_ADMINISTRATOR,
        'description' => PARISH_NAME.' Super Administrator',
        'level' => 1,
        'tenant_id' => $tenant->id,
        'is_custom' => false,
        'role_type' => Role::ROLE_TYPE_TENANT,
        'role_classification' => Role::CLASSIFICATION_PROTECTED_SYSTEM,
        'active' => 1,
    ]);

    $tenantPermissions = Permission::where('active', 1)
        ->where('is_custom', false)
        ->whereNull('tenant_id')
        ->where(function ($query) {
            $query->whereNull('scope')
                ->orWhereIn('scope', [Permission::SCOPE_TENANT, Permission::SCOPE_BOTH]);
        })
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    if ($tenantPermissions !== []) {
        $adminRole->permissions()->sync($tenantPermissions);
    }

    $primaryUser = User::create([
        'tenant_id' => $tenant->id,
        'name' => 'Sacred Heart Admin',
        'email' => ADMIN_EMAIL,
        'contact_number' => '9876543210',
        'user_type' => User::USER_TYPE_PRIMARY_CONTACT,
        'is_primary_admin' => true,
        'role_id' => $adminRole->id,
        'password' => Hash::make(ADMIN_PASSWORD),
        'active' => 1,
        'force_password_change' => false,
        'password_changed_at' => now(),
    ]);
    $primaryUser->roles()->syncWithoutDetaching([$adminRole->id]);
    $addressService->create($primaryUser, $addressPayload, 'primary', true);

    if (class_exists(MinistriesAssociationsDefaultSeeder::class)) {
        (new MinistriesAssociationsDefaultSeeder)->run((int) $tenant->id, (int) $primaryUser->id);
    }
    if (class_exists(DonationCategorySeeder::class)) {
        (new DonationCategorySeeder)->run($tenant->id);
    }
    if (class_exists(TenantSacramentSettingsService::class)) {
        app(TenantSacramentSettingsService::class)->ensureDefaults((int) $tenant->id, (int) $primaryUser->id);
    }

    echo "Created tenant #{$tenant->id} (".PARISH_NAME.")\n";
    echo "Admin user #{$primaryUser->id} email=".ADMIN_EMAIL.' password='.ADMIN_PASSWORD."\n";
});
