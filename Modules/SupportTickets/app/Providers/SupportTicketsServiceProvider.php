<?php

namespace Modules\SupportTickets\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\SupportAccess\Contracts\ExternalHelpdeskTicketAdapter;
use Modules\SupportTickets\Support\NativeSupportTicketAdapter;
use Nwidart\Modules\Traits\PathNamespace;

class SupportTicketsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'SupportTickets';

    protected string $nameLower = 'supporttickets';

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $configPath = module_path($this->name, 'config/config.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->nameLower);
        }

        $this->app->singleton(ExternalHelpdeskTicketAdapter::class, NativeSupportTicketAdapter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\SupportTickets\Console\Commands\ReconcileSupportTicketSlaCommand::class,
            ]);
        }
    }
}
