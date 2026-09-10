<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Authentication\Models\Role;
use Laravel\Passport\Passport;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Models\BishopUpdateRequest;
use Modules\EcclesiasticalData\Models\DioceseManagement;
use Modules\EcclesiasticalData\Policies\BishopManagementPolicy;
use Modules\EcclesiasticalData\Policies\BishopUpdateRequestPolicy;
use Modules\EcclesiasticalData\Policies\DioceseManagementPolicy;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Policies\PermissionPolicy;
use Modules\RolesAndPermissions\Policies\RolePolicy;
use Modules\Tenants\Support\TenantContext;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Role::class => RolePolicy::class,
        Permission::class => PermissionPolicy::class,
        BishopManagement::class => BishopManagementPolicy::class,
        DioceseManagement::class => DioceseManagementPolicy::class,
        BishopUpdateRequest::class => BishopUpdateRequestPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user) {
            if (! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
                return null;
            }

            try {
                $effectiveTenantId = app(TenantContext::class)->effectiveTenantId();
            } catch (\Throwable) {
                $effectiveTenantId = null;
            }

            // Platform operations without an effective tenant retain full bypass.
            if ($effectiveTenantId === null || $effectiveTenantId <= 0) {
                return true;
            }

            return null;
        });

        // Register gates dynamically from permission catalog.
        try {
            Permission::query()
                ->where('active', 1)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->each(function ($permissionName) {
                    Gate::define($permissionName, function ($user) use ($permissionName) {
                        return method_exists($user, 'hasPermission') && $user->hasPermission($permissionName);
                    });
                });
        } catch (\Throwable $e) {
            // Do not break auth bootstrap if permissions table is unavailable during install/migrations.
        }

        // Configure Passport token lifetimes
        Passport::tokensExpireIn(now()->addHours(6));        // Access tokens expire in 6 hours
        Passport::refreshTokensExpireIn(now()->addDays(30)); // Refresh tokens expire in 30 days
        Passport::personalAccessTokensExpireIn(now()->addMonths(6)); // Personal access tokens expire in 6 months
    }
}
