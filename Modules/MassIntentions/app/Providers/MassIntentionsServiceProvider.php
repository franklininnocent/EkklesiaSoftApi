<?php

namespace Modules\MassIntentions\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\MassIntentions\Console\Commands\CloseExpiredMassIntentionsCommand;
use Modules\MassIntentions\DefaultSeeds\MassIntentionCategoriesDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MassIntentionsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'MassIntentions';

    protected string $nameLower = 'massintentions';

    /**
     * @var string[]
     */
    protected array $commands = [
        CloseExpiredMassIntentionsCommand::class,
    ];

    /**
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('mass-intentions:close-expired')
            ->hourly()
            ->withoutOverlapping();
    }

    public function boot(): void
    {
        parent::boot();

        $this->app->afterResolving(DefaultSeedRegistry::class, function (DefaultSeedRegistry $registry): void {
            $registry->register($this->app->make(MassIntentionCategoriesDefaultSeedDefinition::class));
        });
    }
}
