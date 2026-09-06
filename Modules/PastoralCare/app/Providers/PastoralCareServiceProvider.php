<?php

namespace Modules\PastoralCare\Providers;

use Illuminate\Support\ServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;

class PastoralCareServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'PastoralCare';

    protected string $nameLower = 'pastoralcare';

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

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
