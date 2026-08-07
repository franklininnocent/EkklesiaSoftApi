<?php

namespace Modules\MinistriesAssociations\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

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
}
