<?php

namespace Modules\Tenants\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionLifecycleNotificationPublisher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionLifecycleNotificationPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_sends_expired_notice_to_primary_admin(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => true]);

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'parish-admin@example.test',
        ]);

        $mailCalled = false;
        Mail::shouldReceive('raw')
            ->once()
            ->withArgs(function (string $body, callable $callback) use (&$mailCalled, $tenant): bool {
                $mailCalled = str_contains($body, 'cannot save changes')
                    && str_contains($body, (string) $tenant->name);

                return $mailCalled;
            });

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        $this->assertTrue($mailCalled);
    }

    #[Test]
    public function it_skips_when_mail_disabled(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => false]);

        $tenant = Tenant::factory()->active()->create();

        Mail::shouldReceive('raw')->never();

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_skips_when_no_recipients(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => true]);

        $tenant = Tenant::factory()->active()->create();

        Mail::shouldReceive('raw')->never();

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        $this->addToAssertionCount(1);
    }
}
