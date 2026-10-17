<?php

namespace App\Providers;

use App\Support\CaseInsensitiveSearch;
use App\Support\CorsAllowedOrigins;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        // Re-apply on every boot so a config cache baked before FRONTEND_URL
        // was set cannot leave the browser Origin allowlist empty.
        config([
            'cors.allowed_origins' => CorsAllowedOrigins::resolve(
                (string) config('app.env'),
                config('app.frontend_url'),
                config('app.marketing_url'),
            ),
        ]);

        Builder::macro('whereInsensitiveLike', function (string $column, string $pattern, string $boolean = 'and') {
            return CaseInsensitiveSearch::applyColumnLike($this, $column, $pattern, $boolean);
        });

        Builder::macro('orWhereInsensitiveLike', function (string $column, string $pattern) {
            return CaseInsensitiveSearch::applyColumnLike($this, $column, $pattern, 'or');
        });

        Builder::macro('whereMemberFullNameLike', function (string $pattern, string $boolean = 'and') {
            return CaseInsensitiveSearch::applyMemberFullNameLike($this, $pattern, $boolean);
        });

        Builder::macro('orWhereMemberFullNameLike', function (string $pattern) {
            return CaseInsensitiveSearch::applyMemberFullNameLike($this, $pattern, 'or');
        });
    }
}
