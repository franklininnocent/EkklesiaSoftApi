<?php

namespace Modules\SupportAccess\Tests\Unit;

use App\Mail\TransactionalNotificationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Modules\SupportAccess\Models\SupportSession;
use Modules\SupportAccess\Models\SupportSessionSetting;
use Modules\SupportAccess\Services\SupportSessionNotificationPublisher;
use Modules\SupportAccess\Services\SupportSettingsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportSessionNotificationPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function never_mode_skips_delivery(): void
    {
        Mail::fake();

        $settings = Mockery::mock(SupportSettingsService::class);
        $settings->shouldReceive('getOpsSettings')->andReturn(['notification_mode' => 'never']);

        $publisher = new SupportSessionNotificationPublisher($settings);
        $publisher->sessionStarted($this->fakeSession());

        Mail::assertNothingSent();
    }

    #[Test]
    public function immediate_mode_logs_and_mails_when_configured(): void
    {
        config(['supportaccess.notify_to' => 'ops@example.com']);
        Mail::fake();

        $settings = Mockery::mock(SupportSettingsService::class);
        $settings->shouldReceive('getOpsSettings')->andReturn(['notification_mode' => 'immediate']);

        $publisher = new SupportSessionNotificationPublisher($settings);
        $publisher->sessionStarted($this->fakeSession());

        Mail::assertSent(TransactionalNotificationMail::class, function (TransactionalNotificationMail $mail): bool {
            return str_contains($mail->render(), 'Support Access notification');
        });
    }

    #[Test]
    public function digest_mode_enqueues_without_mail(): void
    {
        Mail::fake();

        $settings = Mockery::mock(SupportSettingsService::class);
        $settings->shouldReceive('getOpsSettings')->andReturn(['notification_mode' => 'digest']);

        $publisher = new SupportSessionNotificationPublisher($settings);
        $publisher->sessionEnded($this->fakeSession());

        $row = SupportSessionSetting::query()
            ->where('key', SupportSessionNotificationPublisher::DIGEST_KEY)
            ->first();

        $this->assertNotNull($row);
        $this->assertCount(1, $row->value['items'] ?? []);
        Mail::assertNothingSent();
    }

    #[Test]
    public function flush_digest_sends_combined_mail_and_clears_queue(): void
    {
        config(['supportaccess.notify_to' => 'ops@example.com']);
        Mail::fake();

        SupportSessionSetting::query()->updateOrCreate(
            ['key' => SupportSessionNotificationPublisher::DIGEST_KEY],
            ['value' => [
                'items' => [[
                    'event' => 'session_started',
                    'session_id' => 's1',
                    'tenant_id' => 1,
                    'tenant_name' => 'Parish A',
                    'actor_user_id' => 9,
                    'actor_email' => 'agent@example.com',
                    'queued_at' => now()->toIso8601String(),
                ]],
            ]]
        );

        $settings = Mockery::mock(SupportSettingsService::class);
        $publisher = new SupportSessionNotificationPublisher($settings);

        $count = $publisher->flushDigest();

        $this->assertSame(1, $count);

        Mail::assertSent(TransactionalNotificationMail::class, function (TransactionalNotificationMail $mail): bool {
            return str_contains($mail->render(), 'Support Access digest');
        });

        $row = SupportSessionSetting::query()
            ->where('key', SupportSessionNotificationPublisher::DIGEST_KEY)
            ->first();
        $this->assertSame([], $row?->value['items'] ?? null);
    }

    private function fakeSession(): SupportSession
    {
        $session = new SupportSession([
            'id' => '11111111-1111-1111-1111-111111111111',
            'support_user_id' => 9,
            'tenant_id' => 3,
            'mode' => 'readonly',
            'reason_code' => 'diagnosis',
            'ticket_ref' => 'T-1',
            'status' => SupportSession::STATUS_ACTIVE,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
            'ended_at' => null,
            'ended_reason' => null,
        ]);
        $session->exists = true;
        $session->setRelation('tenant', null);
        $session->setRelation('supportUser', null);

        return $session;
    }
}
