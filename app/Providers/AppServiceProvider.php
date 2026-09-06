<?php

namespace App\Providers;

use App\Support\CaseInsensitiveSearch;
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
