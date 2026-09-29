<?php

namespace Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;

class NotificationsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Notifications';

    protected string $nameLower = 'notifications';

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $this->app->bind(
            \Modules\Notifications\Contracts\NotificationPublisherContract::class,
            \Modules\Notifications\Services\NotificationPublisher::class,
        );

        $configPath = module_path($this->name, 'config/config.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->nameLower);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }
}
