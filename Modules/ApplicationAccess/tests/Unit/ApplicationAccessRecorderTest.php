<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Services\ApplicationAccessRecorder;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['applicationaccess.telemetry_enabled' => true]);
    }

    #[Test]
    public function it_is_idempotent_on_duplicate_event_uuid(): void
    {
        $recorder = app(ApplicationAccessRecorder::class);
        $eventId = (string) Str::uuid();
        $occurredAt = now()->subMinute();

        $first = $recorder->recordAccessEvent([
            'id' => $eventId,
            'event_type' => 'VIEW',
            'occurred_at' => $occurredAt,
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $second = $recorder->recordAccessEvent([
            'id' => $eventId,
            'event_type' => 'VIEW',
            'occurred_at' => $occurredAt->copy()->addHour(),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $this->assertNotNull($first);
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, ApplicationAccessEvent::query()->count());
        $this->assertSame(
            $occurredAt->format('Y-m-d H:i:s'),
            $second?->occurred_at?->format('Y-m-d H:i:s')
        );
    }

    #[Test]
    public function it_noops_when_telemetry_disabled(): void
    {
        config(['applicationaccess.telemetry_enabled' => false]);

        $recorder = app(ApplicationAccessRecorder::class);
        $result = $recorder->recordAccessEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'occurred_at' => now(),
        ]);

        $this->assertNull($result);
        $this->assertSame(0, ApplicationAccessEvent::query()->count());
    }

    #[Test]
    public function it_strips_disallowed_metadata_keys(): void
    {
        $recorder = app(ApplicationAccessRecorder::class);

        $event = $recorder->recordAccessEvent([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'occurred_at' => now(),
            'metadata' => [
                'edge_ray_id' => 'ray-1',
                'password' => 'secret',
                'drop_me' => 'x',
            ],
        ]);

        $this->assertSame(['edge_ray_id' => 'ray-1'], $event?->metadata);
    }
}
