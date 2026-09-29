<?php

namespace Modules\ApplicationAccess\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ApplicationAccess\Models\ApplicationAccessEvent;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamEventPublisher;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationAccessStreamEventPublisherTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_replays_events_after_last_event_id_within_bounds(): void
    {
        config([
            'applicationaccess.sse.replay_max_rows' => 100,
            'applicationaccess.sse.replay_max_seconds' => 120,
        ]);

        $first = ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'occurred_at' => now()->subSeconds(30),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $second = ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'LIST',
            'action' => 'LIST',
            'occurred_at' => now()->subSeconds(10),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $publisher = app(ApplicationAccessStreamEventPublisher::class);
        $events = $publisher->replayFromLastEventId('access:'.$first->id);

        $this->assertCount(1, $events);
        $this->assertSame('access:'.$second->id, $events[0]['stream_id']);
    }

    #[Test]
    public function it_fetches_new_events_since_cursor(): void
    {
        $event = ApplicationAccessEvent::query()->create([
            'id' => (string) Str::uuid(),
            'event_type' => 'VIEW',
            'action' => 'VIEW',
            'occurred_at' => now(),
            'telemetry_source' => ApplicationAccessEnums::TELEMETRY_APPLICATION,
        ]);

        $publisher = app(ApplicationAccessStreamEventPublisher::class);
        $events = $publisher->fetchSince(now()->subMinute());

        $this->assertNotEmpty($events);
        $this->assertSame('access:'.$event->id, $events[0]['stream_id']);
    }
}
