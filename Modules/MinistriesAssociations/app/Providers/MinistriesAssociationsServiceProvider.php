<?php

namespace Modules\MinistriesAssociations\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\MinistriesAssociations\DefaultSeeds\OrganizationCategoriesDefaultSeedDefinition;
use Modules\MinistriesAssociations\DefaultSeeds\OrganizationTypesDefaultSeedDefinition;
use Modules\MinistriesAssociations\DefaultSeeds\PositionsDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;

class MinistriesAssociationsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'MinistriesAssociations';

    protected string $nameLower = 'ministriesassociations';

    /** @var string[] */
    protected array $commands = [
        \Modules\MinistriesAssociations\Console\Commands\BackfillMinistriesAssociationsDefaults::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->app->afterResolving(DefaultSeedRegistry::class, function (DefaultSeedRegistry $registry): void {
            $registry->register($this->app->make(OrganizationCategoriesDefaultSeedDefinition::class));
            $registry->register($this->app->make(OrganizationTypesDefaultSeedDefinition::class));
            $registry->register($this->app->make(PositionsDefaultSeedDefinition::class));
        });
    }
}
