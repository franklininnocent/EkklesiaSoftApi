<?php

namespace Modules\Tenants\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenants\Database\Seeders\SubscriptionEnforcementE2eSeeder;
use Modules\Tenants\Services\SubscriptionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionEnforcementSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function smoke_command_fails_when_require_enforcement_and_flag_off(): void
    {
        config(['tenants.subscription.view_only_locked_enforcement' => false]);

        $this->artisan('tenants:subscription-enforcement-smoke', ['--require-enforcement' => true])
            ->assertFailed();
    }

    #[Test]
    public function smoke_command_passes_with_enforcement_and_e2e_seed(): void
    {
        config([
            'tenants.subscription.write_policy' => SubscriptionService::WRITE_POLICY_READ_ONLY_WHEN_EXPIRED,
            'tenants.subscription.view_only_locked_enforcement' => true,
        ]);

        $this->seed(SubscriptionEnforcementE2eSeeder::class);

        $this->artisan('tenants:subscription-enforcement-smoke', ['--require-enforcement' => true])
            ->assertSuccessful();
    }
}
