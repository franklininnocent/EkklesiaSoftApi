<?php

namespace Modules\Tenants\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Modules\Tenants\Export\Contributors\BccDataExportContributor;
use Modules\Tenants\Export\Contributors\ChurchProfileDataExportContributor;
use Modules\Tenants\Export\Contributors\DonationsDataExportContributor;
use Modules\Tenants\Export\Contributors\FamiliesDataExportContributor;
use Modules\Tenants\Export\Contributors\MinistriesDataExportContributor;
use Modules\Tenants\Export\Contributors\SacramentsDataExportContributor;
use Modules\Tenants\Export\Contributors\UsersDataExportContributor;
use Modules\Tenants\Export\TenantDataExportContributorRegistry;
use Modules\Tenants\Support\SlowQueryLogger;
use Modules\Tenants\Support\TenantRlsManager;
use Modules\Tenants\Contracts\SupportSessionResolver;
use Modules\Tenants\Support\NullSupportSessionResolver;
use Modules\Tenants\Support\TenantContext;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class TenantsServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Tenants';

    protected string $nameLower = 'tenants';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        
        // Load module migrations
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));

        DB::beforeStartingTransaction(static function ($connection): void {
            TenantRlsManager::applyLocalTenantFromContext($connection);
        });

        SlowQueryLogger::register();

        // Load module configuration
        $configPath = module_path($this->name, 'config/tenants.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, 'tenants');
        }

        // Publish module configuration
        $this->publishes([
            module_path($this->name, 'config/tenants.php') => config_path('tenants.php'),
        ], 'tenants-config');

        // Publish module migrations
        $this->publishes([
            module_path($this->name, 'database/migrations') => database_path('migrations'),
        ], 'tenants-migrations');
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->singleton(SupportSessionResolver::class, NullSupportSessionResolver::class);
        $this->app->scoped(TenantContext::class, static fn () => TenantContext::empty());
        $this->app->singleton(\Modules\Tenants\Services\TenantAuthorizationService::class);
        $this->app->singleton(\Modules\Tenants\Services\PlatformAuditLogger::class);
        $this->app->singleton(\Modules\Tenants\Support\AuditPiiRedactor::class);
        $this->app->singleton(
            \Modules\Tenants\Support\SubscriptionRouteAllowlist::class,
            static fn () => \Modules\Tenants\Support\SubscriptionRouteAllowlist::fromConfig()
        );

        $this->app->singleton(TenantDataExportContributorRegistry::class, static function () {
            $registry = new TenantDataExportContributorRegistry;
            foreach ([
                new UsersDataExportContributor,
                new FamiliesDataExportContributor,
                new BccDataExportContributor,
                new ChurchProfileDataExportContributor,
                new DonationsDataExportContributor,
                new MinistriesDataExportContributor,
                new SacramentsDataExportContributor,
            ] as $contributor) {
                $registry->register($contributor);
            }

            return $registry;
        });

        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            \Modules\Tenants\Console\Commands\CleanupAuditLogs::class,
            \Modules\Tenants\Console\Commands\AssignPermissionsToAdministrators::class,
            \Modules\Tenants\Console\Commands\BackfillTenantRbac::class,
            \Modules\Tenants\Console\Commands\CleanupExpiredTenantDataExports::class,
            \Modules\Tenants\Console\Commands\SeedLoadTestData::class,
            \Modules\Tenants\Console\Commands\RunLoadTestBenchmark::class,
            \Modules\Tenants\Console\Commands\CleanupLoadTestData::class,
            \Modules\Tenants\Console\Commands\ExportCrossTenantPenetrationManifest::class,
            \Modules\Tenants\Console\Commands\PlatformProductionCheck::class,
            \Modules\Tenants\Console\Commands\ProcessSubscriptionLifecycle::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            if (config('tenants.platform.scheduler.export_cleanup_enabled', true)) {
                $schedule->command('tenants:cleanup-exports')
                    ->dailyAt((string) config('tenants.platform.scheduler.export_cleanup_time', '02:30'))
                    ->withoutOverlapping();
            }

            if (config('tenants.platform.scheduler.audit_cleanup_enabled', true)) {
                $schedule->command('tenants:cleanup-audit --no-interaction')
                    ->weeklyOn(
                        $this->weeklyDayNumber((string) config('tenants.platform.scheduler.audit_cleanup_day', 'sunday')),
                        (string) config('tenants.platform.scheduler.audit_cleanup_time', '03:00'),
                    )
                    ->withoutOverlapping();
            }

            if (config('tenants.subscription.lifecycle.scheduler_enabled', true)) {
                $schedule->command('tenants:subscription-lifecycle')
                    ->hourlyAt((int) substr((string) config('tenants.subscription.lifecycle.scheduler_time', '01:15'), 3, 2))
                    ->withoutOverlapping();
            }
        });
    }

    private function weeklyDayNumber(string $day): int
    {
        return match (strtolower($day)) {
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => 0,
        };
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $this->nameLower.'.'.$config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->merge_config_from($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function merge_config_from(string $path, string $key): void
    {
        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\' . $this->name . '\\View\\Components', $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
