<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Configure Passport token lifetimes
        Passport::tokensExpireIn(now()->addHours(6));        // Access tokens expire in 6 hours
        Passport::refreshTokensExpireIn(now()->addDays(30)); // Refresh tokens expire in 30 days
        Passport::personalAccessTokensExpireIn(now()->addMonths(6)); // Personal access tokens expire in 6 months
    }
}
