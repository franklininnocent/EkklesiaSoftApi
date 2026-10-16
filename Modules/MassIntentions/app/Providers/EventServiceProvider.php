<?php

namespace Modules\MassIntentions\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\MassIntentions\Policies\MassIntentionsModulePolicy;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}

    public function boot(): void
    {
        Gate::define('massIntentions.viewModuleStatus', [MassIntentionsModulePolicy::class, 'viewStatus']);
    }
}
