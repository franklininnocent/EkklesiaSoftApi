<?php

namespace Modules\Tenants\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformProductionCheckTest extends TestCase
{
    #[Test]
    public function production_check_command_runs_in_testing_environment(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'tenants.isolation.orm_global_scope' => true,
            'tenants.platform.health.token' => 'test-token',
        ]);

        $this->artisan('platform:production-check')
            ->assertExitCode(0);
    }
}
