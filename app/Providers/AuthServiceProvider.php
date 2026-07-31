<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Authentication\Models\Role;
use Laravel\Passport\Passport;
use Modules\RolesAndPermissions\Models\Permission;
use Modules\RolesAndPermissions\Policies\PermissionPolicy;
use Modules\RolesAndPermissions\Policies\RolePolicy;

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
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function ($user) {
            return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() ? true : null;
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
