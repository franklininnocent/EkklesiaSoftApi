<?php

namespace Modules\SupportAccess\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\SupportAccess\Console\Commands\ExpireSupportSessionsCommand;
use Modules\SupportAccess\Console\Commands\FlushSupportNotificationDigestCommand;
use Modules\SupportAccess\Contracts\ExternalHelpdeskTicketAdapter;
use Modules\SupportAccess\Support\DatabaseSupportSessionResolver;
use Modules\SupportAccess\Support\NullExternalHelpdeskTicketAdapter;
use Modules\Tenants\Contracts\SupportSessionResolver;
use Nwidart\Modules\Traits\PathNamespace;

class SupportAccessServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'SupportAccess';

    protected string $nameLower = 'supportaccess';

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Override Null resolver from Tenants with DB-backed resolution.
        $this->app->singleton(SupportSessionResolver::class, DatabaseSupportSessionResolver::class);
        $this->app->singleton(ExternalHelpdeskTicketAdapter::class, NullExternalHelpdeskTicketAdapter::class);

        $configPath = module_path($this->name, 'config/config.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->nameLower);
        }
    }

    public function boot(): void
    {
        $this->commands([
            FlushSupportNotificationDigestCommand::class,
            ExpireSupportSessionsCommand::class,
        ]);

        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('support:flush-notification-digest')->hourly();
            $schedule->command('support:expire-sessions')->everyMinute();
        });
    }
}
