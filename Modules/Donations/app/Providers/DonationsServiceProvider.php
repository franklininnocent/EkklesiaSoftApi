<?php

namespace Modules\Donations\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Donations\DefaultSeeds\DonationCategoriesDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;

class DonationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Donations';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'donations';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        \Modules\Donations\Console\Commands\GenerateScheduledContributionDuesCommand::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('donations:generate-scheduled-dues')->dailyAt('01:00');
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->afterResolving(DefaultSeedRegistry::class, function (DefaultSeedRegistry $registry): void {
            $registry->register($this->app->make(DonationCategoriesDefaultSeedDefinition::class));
        });
    }
}
