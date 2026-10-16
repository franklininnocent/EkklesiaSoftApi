<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductionDebugGuardTest extends TestCase
{
    #[Test]
    public function production_boot_forces_app_debug_off(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->boot();

        $this->assertFalse((bool) config('app.debug'));
    }
}
