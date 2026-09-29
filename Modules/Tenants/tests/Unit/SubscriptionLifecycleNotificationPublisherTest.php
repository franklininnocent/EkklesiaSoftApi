<?php

namespace Modules\Tenants\Tests\Unit;

use App\Mail\TransactionalNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Services\SubscriptionLifecycleNotificationPublisher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionLifecycleNotificationPublisherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_sends_expired_notice_to_primary_admin(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => true]);
        Mail::fake();

        $tenant = Tenant::factory()->active()->create([
            'subscription_ends_at' => now()->subDays(10),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'active' => 1,
            'is_primary_admin' => 1,
            'email' => 'parish-admin@example.test',
        ]);

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        Mail::assertSent(TransactionalNotificationMail::class, function (TransactionalNotificationMail $mail) use ($tenant): bool {
            $html = $mail->render();

            return str_contains($html, 'cannot save changes')
                && str_contains($html, (string) $tenant->name);
        });
    }

    #[Test]
    public function it_skips_when_mail_disabled(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => false]);
        Mail::fake();

        $tenant = Tenant::factory()->active()->create();

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        Mail::assertNothingSent();
    }

    #[Test]
    public function it_skips_when_no_recipients(): void
    {
        config(['tenants.subscription.lifecycle.mail_enabled' => true]);
        Mail::fake();

        $tenant = Tenant::factory()->active()->create();

        app(SubscriptionLifecycleNotificationPublisher::class)->notifyTransition($tenant, 'entered_expired');

        Mail::assertNothingSent();
    }
}
