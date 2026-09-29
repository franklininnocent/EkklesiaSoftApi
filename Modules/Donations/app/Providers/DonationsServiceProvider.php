<?php

namespace Modules\Donations\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Donations\Console\Commands\BackfillDonationDueStatusChangedCommand;
use Modules\Donations\Console\Commands\ExpireDonationReportExportsCommand;
use Modules\Donations\Console\Commands\GenerateScheduledContributionDuesCommand;
use Modules\Donations\Console\Commands\SeedStewardshipDemoCommand;
use Modules\Donations\DefaultSeeds\DonationCategoriesDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;
use Nwidart\Modules\Support\ModuleServiceProvider;

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
        GenerateScheduledContributionDuesCommand::class,
        SeedStewardshipDemoCommand::class,
        ExpireDonationReportExportsCommand::class,
        BackfillDonationDueStatusChangedCommand::class,
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
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('donations:generate-scheduled-dues')->dailyAt('01:00')->withoutOverlapping();
        $schedule->command('donations:expire-report-exports')->dailyAt('02:30')->withoutOverlapping();
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->afterResolving(DefaultSeedRegistry::class, function (DefaultSeedRegistry $registry): void {
            $registry->register($this->app->make(DonationCategoriesDefaultSeedDefinition::class));
        });
    }
}
