<?php

namespace Modules\ApplicationAccess\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\ApplicationAccess\Models\ApplicationSecurityEvent;
use Modules\ApplicationAccess\Models\ApplicationSecuritySignal;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'applicationaccess.retention.access_events_days' => 30,
            'applicationaccess.retention.security_events_days' => 30,
            'applicationaccess.retention.signals_days' => 30,
            'applicationaccess.retention.ended_sessions_days' => 30,
        ]);
    }

    #[Test]
    public function retention_prunes_old_rows_but_keeps_active_sessions(): void
    {
        $oldOccurredAt = now()->subDays(45);
        $recentOccurredAt = now()->subDay();

        ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'occurred_at' => $oldOccurredAt,
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'occurred_at' => $recentOccurredAt,
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        ApplicationSecurityEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'LOGIN_FAILURE',
            'detected_at' => $oldOccurredAt,
        ]);

        ApplicationSecuritySignal::query()->create([
            'id' => (string) Str::uuid(),
            'signal_type' => 'REPEATED_403',
            'source_ip' => '198.51.100.10',
            'window_start' => $oldOccurredAt,
            'window_end' => $oldOccurredAt->copy()->addMinute(),
            'first_seen' => $oldOccurredAt,
            'last_seen' => $oldOccurredAt,
        ]);

        $endedSession = app(ApplicationAccessSessionRepository::class)->create([
            'identity_type' => ApplicationAccessEnums::IDENTITY_EKKLESIA_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_EKKLESIA,
            'status' => ApplicationAccessEnums::SESSION_ENDED,
            'started_at' => $oldOccurredAt,
            'last_activity_at' => $oldOccurredAt,
            'ended_at' => $oldOccurredAt,
            'end_reason' => 'logout',
        ]);

        $activeSession = app(ApplicationAccessSessionRepository::class)->create([
            'identity_type' => ApplicationAccessEnums::IDENTITY_EKKLESIA_USER,
            'access_context' => ApplicationAccessEnums::CONTEXT_EKKLESIA,
            'status' => ApplicationAccessEnums::SESSION_ACTIVE,
            'started_at' => $oldOccurredAt,
            'last_activity_at' => now(),
        ]);

        $this->artisan('application-access:retention')
            ->assertSuccessful();

        $this->assertSame(1, ApplicationAccessEvent::query()->count());
        $this->assertSame(0, ApplicationSecurityEvent::query()->where('event_type', 'LOGIN_FAILURE')->count());
        $this->assertSame(0, ApplicationSecuritySignal::query()->count());
        $this->assertNull(ApplicationAccessSession::query()->find($endedSession->id));
        $this->assertNotNull(ApplicationAccessSession::query()->find($activeSession->id));

        $this->assertDatabaseHas('application_security_events', [
            'event_type' => 'PRIVILEGED_OPERATION',
            'reason_code' => 'retention_run',
        ]);
    }

    #[Test]
    public function retention_expires_due_ip_blocks(): void
    {
        $rule = ApplicationIpBlockRule::query()->create([
            'ip_address' => '203.0.113.50',
            'scope' => 'API',
            'reason' => 'Temporary block',
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('application-access:retention')
            ->assertSuccessful();

        $rule->refresh();
        $this->assertNotNull($rule->revoked_at);
    }

    #[Test]
    public function retention_dry_run_does_not_delete_rows(): void
    {
        ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'occurred_at' => now()->subDays(45),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $this->artisan('application-access:retention', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, ApplicationAccessEvent::query()->count());
        $this->assertDatabaseMissing('application_security_events', [
            'reason_code' => 'retention_run',
        ]);
    }
}
